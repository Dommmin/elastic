<?php

namespace App\Seller360;

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
 * `data`, `meta` i `doc` lecą jako obiekty JSON: pusta tablica PHP to `[]` (lista), a skrypt
 * robi `new HashMap(params.data)`, który na liście rzuca wyjątek.
 */
class SectionWriter
{
    private const SCRIPT = 'seller360-apply-section';

    public function __construct(
        private readonly Client $client,
        private readonly Seller360Metrics $metrics,
        private readonly string $writeIndex = 'seller-360-write',
        private readonly string $publicWriteIndex = 'seller-360-public-write',
        private readonly bool $refresh = false,
        private readonly LoggerInterface $logger = new NullLogger,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data  pełny stan sekcji
     * @param  array<string, mixed>  $docFields  pola dokumentu (`nip`, `region`, `synthetic`), dopisywane razem z sekcją
     * @return string `created|updated|noop` (wynik zapisu prywatnego)
     */
    public function apply(int $sellerId, string $section, array $data, SectionMeta $meta, array $docFields = []): string
    {
        $updatedAt = new \DateTimeImmutable;
        $result = $this->update($this->writeIndex, $sellerId, $section, $data, $meta, $docFields, $updatedAt);

        $public = PublicProjection::filter($section, $data);
        if ($public !== null) {
            $this->update($this->publicWriteIndex, $sellerId, $section, $public, $meta, $docFields, $updatedAt);
        }

        $this->metrics->sectionWrite($section, $result);

        return $result;
    }

    /**
     * Hurtowy zapis dla przeliczeń. Wynik per element, w kolejności wejścia (wynik zapisu prywatnego):
     * `created|updated|noop`, a przy odrzuceniu przez ES `error`. Błąd całego żądania leci wyjątkiem.
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
        $body = [];
        $privatePositions = [];
        $publicPositions = [];
        foreach ($items as $i => $item) {
            $docFields = $item['docFields'] ?? [];
            $privatePositions[] = intdiv(\count($body), 2);
            array_push($body, ...$this->action($this->writeIndex, $item['sellerId'], $item['section'], $item['data'], $item['meta'], $docFields, $updatedAt));

            $public = PublicProjection::filter($item['section'], $item['data']);
            if ($public !== null) {
                $publicPositions[$i] = intdiv(\count($body), 2);
                array_push($body, ...$this->action($this->publicWriteIndex, $item['sellerId'], $item['section'], $public, $item['meta'], $docFields, $updatedAt));
            }
        }

        $response = $this->client->bulk(['body' => $body, 'refresh' => $this->refresh ? 'true' : 'false'])->asArray();
        $entries = array_values($response['items'] ?? []);
        if (\count($entries) !== \count($body) / 2) {
            throw new \UnexpectedValueException(\sprintf('_bulk: wysłano %d akcji, odpowiedź ma %d pozycji.', \count($body) / 2, \count($entries)));
        }

        $results = [];
        foreach ($items as $i => $item) {
            $entry = $entries[$privatePositions[$i]];
            $outcome = $entry[array_key_first($entry)];
            $status = (int) ($outcome['status'] ?? 0);
            $result = ($status >= 200 && $status < 300) ? (string) ($outcome['result'] ?? 'updated') : 'error';
            $results[] = $result;
            $this->metrics->sectionWrite($item['section'], $result);

            // Zapis publiczny nie zmienia wyniku, ale nieudanego nie gubimy po cichu.
            if (isset($publicPositions[$i])) {
                $public = $entries[$publicPositions[$i]];
                $publicOutcome = $public[array_key_first($public)];
                $publicStatus = (int) ($publicOutcome['status'] ?? 0);
                if ($publicStatus < 200 || $publicStatus >= 300) {
                    $this->logger->warning('Zapis kopii publicznej seller-360 odrzucony.', [
                        'seller_id' => $item['sellerId'],
                        'section' => $item['section'],
                        'status' => $publicStatus,
                        'error' => $publicOutcome['error']['reason'] ?? $publicOutcome['error']['type'] ?? null,
                    ]);
                }
            }
        }

        return $results;
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
