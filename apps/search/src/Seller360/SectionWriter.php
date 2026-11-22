<?php

namespace App\Seller360;

use App\Seller360\Rebuild\WriteTargets;
use Elastic\Elasticsearch\Client;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Zapis sekcji do `seller-360` i filtrowanej kopii do `seller-360-public` (ETAP 13).
 *
 * Jedna operacja to `_update` ze `scripted_upsert` i skryptem `seller360-apply-section`:
 * strażnik wersji w skrypcie zwraca `noop`, gdy zapisana wersja sekcji jest >= przychodzącej,
 * więc kolejność i powtórki zdarzeń nie psują stanu. Zapis publiczny idzie ZAWSZE, także gdy prywatny
 * był `noop` — dzięki temu ponowienie po awarii między zapisami domyka kopię publiczną.
 *
 * Cele zapisu (ETAP 13, przebudowa): pary `{main, public}` z {@see WriteTargets} czytane przy każdym zapisie
 * i każdej partii. Zwykle to jedna para aliasów, w trakcie przebudowy dochodzi para nowych indeksów. Sekcja idzie
 * do KAŻDEJ pary, a zwracany wynik to wynik pierwszej (aliasów, czyli indeksu, z którego czytają odbiorcy).
 * Bez `WriteTargets` (testy) jedyną parą są `$writeIndex` i `$publicWriteIndex`. `forPairs()` daje writer
 * ze stałą listą par — tak przebudowa kieruje przeliczenia (offers, sales, complaints) tylko na nowe indeksy.
 *
 * `data`, `meta` i `doc` lecą jako obiekty JSON: pusta tablica PHP to `[]` (lista), a skrypt
 * robi `new HashMap(params.data)`, który na liście rzuca wyjątek.
 */
class SectionWriter
{
    private const SCRIPT = 'seller360-apply-section';

    /**
     * @param  list<array{main: string, public: string}>|null  $pairs  stała lista par (z {@see self::forPairs()})
     */
    public function __construct(
        private readonly Client $client,
        private readonly Seller360Metrics $metrics,
        private readonly string $writeIndex = 'seller-360-write',
        private readonly string $publicWriteIndex = 'seller-360-public-write',
        private readonly bool $refresh = false,
        private readonly LoggerInterface $logger = new NullLogger,
        private readonly ?WriteTargets $targets = null,
        private readonly ?array $pairs = null,
    ) {
    }

    /**
     * Ten sam writer ze stałą listą par (bez `WriteTargets`).
     *
     * @param  list<array{main: string, public: string}>  $pairs
     */
    public function forPairs(array $pairs): self
    {
        return new self($this->client, $this->metrics, $this->writeIndex, $this->publicWriteIndex, $this->refresh, $this->logger, null, $pairs);
    }

    /** @return list<array{main: string, public: string}> */
    public function pairs(): array
    {
        return $this->pairs ?? $this->targets?->current() ?? [['main' => $this->writeIndex, 'public' => $this->publicWriteIndex]];
    }

    /**
     * @param  array<string, mixed>  $data  pełny stan sekcji
     * @param  array<string, mixed>  $docFields  pola dokumentu (`nip`, `region`, `synthetic`), dopisywane razem z sekcją
     * @return string `created|updated|noop` (wynik zapisu prywatnego pierwszej pary)
     */
    public function apply(int $sellerId, string $section, array $data, SectionMeta $meta, array $docFields = []): string
    {
        $updatedAt = new \DateTimeImmutable;
        $public = PublicProjection::filter($section, $data);
        $result = null;
        foreach ($this->pairs() as $pair) {
            $main = $this->update($pair['main'], $sellerId, $section, $data, $meta, $docFields, $updatedAt);
            $result ??= $main;
            if ($public !== null) {
                $this->update($pair['public'], $sellerId, $section, $public, $meta, $docFields, $updatedAt);
            }
        }

        $this->metrics->sectionWrite($section, $result);

        return $result;
    }

    /**
     * Hurtowy zapis dla przeliczeń: jeden `_bulk` z akcjami dla wszystkich par. Wynik per element, w kolejności
     * wejścia (wynik zapisu prywatnego pierwszej pary): `created|updated|noop`, a `error`, gdy ES odrzucił zapis
     * prywatny w KTÓREJKOLWIEK parze (w trakcie przebudowy nowy indeks nie może zgubić zapisu po cichu, wołający
     * ponawia). Błąd całego żądania leci wyjątkiem.
     *
     * @param  list<array{sellerId: int, section: string, data: array<string, mixed>, meta: SectionMeta, docFields?: array<string, mixed>}>  $items
     * @return list<string>
     */
    public function applyMany(array $items): array
    {
        if ($items === []) {
            return [];
        }

        $updatedAt = new \DateTimeImmutable;
        $pairs = $this->pairs();
        $body = [];
        /** @var array<int, list<int>> $privatePositions element => pozycje zapisów prywatnych (pierwsza para pierwsza) */
        $privatePositions = [];
        /** @var array<int, list<int>> $publicPositions */
        $publicPositions = [];
        foreach ($items as $i => $item) {
            $docFields = $item['docFields'] ?? [];
            $public = PublicProjection::filter($item['section'], $item['data']);
            foreach ($pairs as $pair) {
                $privatePositions[$i][] = intdiv(\count($body), 2);
                array_push($body, ...$this->action($pair['main'], $item['sellerId'], $item['section'], $item['data'], $item['meta'], $docFields, $updatedAt));
                if ($public !== null) {
                    $publicPositions[$i][] = intdiv(\count($body), 2);
                    array_push($body, ...$this->action($pair['public'], $item['sellerId'], $item['section'], $public, $item['meta'], $docFields, $updatedAt));
                }
            }
        }

        $response = $this->client->bulk(['body' => $body, 'refresh' => $this->refresh ? 'true' : 'false'])->asArray();
        $entries = array_values($response['items'] ?? []);
        if (\count($entries) !== \count($body) / 2) {
            throw new \UnexpectedValueException(\sprintf('_bulk: wysłano %d akcji, odpowiedź ma %d pozycji.', \count($body) / 2, \count($entries)));
        }

        $results = [];
        foreach ($items as $i => $item) {
            $result = null;
            foreach ($privatePositions[$i] as $position) {
                $outcome = $this->outcome($entries[$position]);
                $ok = $outcome['status'] >= 200 && $outcome['status'] < 300;
                $result ??= $ok ? (string) ($outcome['raw']['result'] ?? 'updated') : 'error';
                if (! $ok) {
                    $result = 'error';
                    $this->logger->warning('Zapis sekcji seller-360 odrzucony.', ['seller_id' => $item['sellerId'], 'section' => $item['section'], 'index' => $outcome['raw']['_index'] ?? null, 'status' => $outcome['status'], 'error' => $outcome['error']]);
                }
            }
            $results[] = (string) $result;
            $this->metrics->sectionWrite($item['section'], (string) $result);

            // Zapis publiczny nie zmienia wyniku, ale nieudanego nie gubimy po cichu.
            foreach ($publicPositions[$i] ?? [] as $position) {
                $outcome = $this->outcome($entries[$position]);
                if ($outcome['status'] < 200 || $outcome['status'] >= 300) {
                    $this->logger->warning('Zapis kopii publicznej seller-360 odrzucony.', [
                        'seller_id' => $item['sellerId'],
                        'section' => $item['section'],
                        'index' => $outcome['raw']['_index'] ?? null,
                        'status' => $outcome['status'],
                        'error' => $outcome['error'],
                    ]);
                }
            }
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $entry  pozycja `items` z odpowiedzi `_bulk`
     * @return array{status: int, error: ?string, raw: array<string, mixed>}
     */
    private function outcome(array $entry): array
    {
        $raw = $entry[array_key_first($entry)];

        return ['status' => (int) ($raw['status'] ?? 0), 'error' => $raw['error']['reason'] ?? $raw['error']['type'] ?? null, 'raw' => $raw];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $docFields
     */
    private function update(string $index, int $sellerId, string $section, array $data, SectionMeta $meta, array $docFields, \DateTimeImmutable $updatedAt): string
    {
        $params = ['index' => $index, 'id' => (string) $sellerId, 'retry_on_conflict' => 3, 'body' => $this->script($section, $data, $meta, $docFields, $updatedAt)];
        if ($this->refresh) {
            $params['refresh'] = 'true';
        }

        return (string) $this->client->update($params)->asArray()['result'];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $docFields
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function action(string $index, int $sellerId, string $section, array $data, SectionMeta $meta, array $docFields, \DateTimeImmutable $updatedAt): array
    {
        return [
            ['update' => ['_index' => $index, '_id' => (string) $sellerId, 'retry_on_conflict' => 3]],
            $this->script($section, $data, $meta, $docFields, $updatedAt),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $docFields
     * @return array<string, mixed>
     */
    private function script(string $section, array $data, SectionMeta $meta, array $docFields, \DateTimeImmutable $updatedAt): array
    {
        $params = ['section' => $section, 'data' => (object) $data, 'meta' => (object) $meta->toArray($updatedAt)];
        if ($docFields !== []) {
            $params['doc'] = (object) $docFields;
        }

        return ['scripted_upsert' => true, 'upsert' => new \stdClass, 'script' => ['id' => self::SCRIPT, 'params' => $params]];
    }
}
