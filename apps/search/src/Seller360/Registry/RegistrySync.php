<?php

namespace App\Seller360\Registry;

use App\Seller360\SectionMeta;
use App\Seller360\SectionWriter;
use Elastic\Elasticsearch\Client;
use Psr\Log\LoggerInterface;

/**
 * Synchronizacja sekcji `registry` jednego sprzedawcy (ETAP 13).
 *
 * Źródło może paść, a kolejka zdarzeń nie może na tym stać: ten kod żyje w osobnym procesie
 * (`seller360:registry:sync --loop`), a porażka kończy się zapisem, nie wyjątkiem:
 *   - sukces: pełna sekcja ze `status: ok`, kopia w Redisie `seller360:registry:<nip>` na 24 h;
 *   - porażka z cache: dane z cache ze `status: stale` i `error`;
 *   - porażka bez cache: pusta sekcja, same `_meta` ze `status: error`.
 * Wersja to czas w ms także dla stale/error, żeby nie zostały zignorowane jako starsze od wcześniejszego ok.
 * Ocena kuriera pochodzi z indeksu `courier-ratings` (dokument o `_id` = courier_id; powstaje w Task 13),
 * brak indeksu, dokumentu lub uprawnień (404/403) daje `null`.
 */
class RegistrySync
{
    public const CACHE_TTL_SECONDS = 86400;

    /** @var \Closure(): float */
    private readonly \Closure $now;

    /**
     * @param  (\Closure(): float)|null  $now  zegar w sekundach (podmieniany w testach)
     */
    public function __construct(
        private readonly RegistryClient $client,
        private readonly CircuitBreaker $breaker,
        private readonly SectionWriter $writer,
        private readonly \Redis $redis,
        private readonly Client $es,
        private readonly LoggerInterface $logger,
        ?\Closure $now = null,
        private readonly string $ratingsIndex = 'courier-ratings',
    ) {
        $this->now = $now ?? static fn (): float => microtime(true);
    }

    public function syncSeller(int $sellerId, string $nip): void
    {
        $now = ($this->now)();
        $version = (int) round($now * 1000);
        $sourceTs = (new \DateTimeImmutable)->setTimestamp((int) $now);

        $error = null;
        if ($this->breaker->allow()) {
            try {
                $data = $this->fetch($nip);
                $this->breaker->success();
                $this->cacheSet($nip, $data);
                $this->writer->apply($sellerId, 'registry', $data, new SectionMeta($version, $sourceTs), ['nip' => $nip]);

                return;
            } catch (RegistryUnavailable $e) {
                $this->breaker->failure();
                $error = $e->getMessage();
                $this->logger->warning('Rejestr niedostępny, sekcja registry z cache lub error.', ['seller_id' => $sellerId, 'error' => $error]);
            }
        } else {
            $error = 'Bezpiecznik rejestru otwarty.';
        }

        $cached = $this->cacheGet($nip);
        if ($cached !== null) {
            $this->writer->apply($sellerId, 'registry', $cached, new SectionMeta($version, $sourceTs, 'stale', $error), ['nip' => $nip]);

            return;
        }
        $this->writer->apply($sellerId, 'registry', [], new SectionMeta($version, $sourceTs, 'error', $error), ['nip' => $nip]);
    }

    /** @return array<string, mixed> */
    private function fetch(string $nip): array
    {
        $company = $this->client->company($nip);
        $courierId = $this->client->courierAssignment($nip);
        $rating = $this->rating($courierId);

        return [
            'legal_name' => $company['legal_name'] ?? null,
            'vat_status' => $company['vat_status'] ?? null,
            'registered_at' => $company['registered_at'] ?? null,
            'courier' => ['id' => $courierId, 'on_time_rate' => $rating['on_time_rate'], 'rated_at' => $rating['rated_at']],
        ];
    }

    /** @return array{on_time_rate: ?float, rated_at: ?string} */
    private function rating(string $courierId): array
    {
        try {
            $source = $this->es->get(['index' => $this->ratingsIndex, 'id' => $courierId])->asArray()['_source'] ?? [];
        } catch (\Throwable $e) {
            // Indeks powstaje dopiero z courier:poll (Task 13): do tego czasu 404 (brak dokumentu/indeksu),
            // a gdy rola searchsvc nie obejmuje jeszcze `courier-ratings`, 403. Oba są oczekiwane i ciche.
            $expected = \in_array($e->getCode(), [403, 404], true);
            $this->logger->log($expected ? 'debug' : 'warning', 'Nie udało się odczytać oceny kuriera.', ['courier_id' => $courierId, 'error' => $e->getMessage()]);

            return ['on_time_rate' => null, 'rated_at' => null];
        }

        return [
            'on_time_rate' => isset($source['on_time_rate']) ? (float) $source['on_time_rate'] : null,
            'rated_at' => $source['rated_at'] ?? null,
        ];
    }

    /** @param array<string, mixed> $data */
    private function cacheSet(string $nip, array $data): void
    {
        try {
            $this->redis->set($this->cacheKey($nip), json_encode($data, \JSON_THROW_ON_ERROR), ['EX' => self::CACHE_TTL_SECONDS]);
        } catch (\Throwable $e) {
            $this->logger->warning('Nie udało się zapisać cache rejestru.', ['error' => $e->getMessage()]);
        }
    }

    /** @return array<string, mixed>|null */
    private function cacheGet(string $nip): ?array
    {
        try {
            $raw = $this->redis->get($this->cacheKey($nip));
        } catch (\Throwable $e) {
            $this->logger->warning('Nie udało się odczytać cache rejestru.', ['error' => $e->getMessage()]);

            return null;
        }
        if (! \is_string($raw)) {
            return null;
        }
        $data = json_decode($raw, associative: true);

        return \is_array($data) ? $data : null;
    }

    private function cacheKey(string $nip): string
    {
        return 'seller360:registry:'.$nip;
    }
}
