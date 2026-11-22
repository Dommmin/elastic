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
 *   - porażka z cache: dane z cache ze `status: stale` i `error`, a `source_ts` to chwila ostatniego udanego
 *     pobrania (lag `updated_at − source_ts` rośnie przez całą awarię, ruling R6); cache sprzed tej zmiany
 *     (same dane, bez `fetched_at`) dostaje `source_ts` = teraz i wygasa w ciągu 24 h;
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
                $this->cacheSet($nip, $data, (int) $now);
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
            [$data, $fetchedAt] = $cached;
            $staleTs = $fetchedAt !== null ? (new \DateTimeImmutable)->setTimestamp($fetchedAt) : $sourceTs;
            $this->writer->apply($sellerId, 'registry', $data, new SectionMeta($version, $staleTs, 'stale', $error), ['nip' => $nip]);

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
    private function cacheSet(string $nip, array $data, int $fetchedAt): void
    {
        try {
            $entry = ['fetched_at' => $fetchedAt, 'data' => $data];
            $this->redis->set(self::cacheKey($nip), json_encode($entry, \JSON_THROW_ON_ERROR), ['EX' => self::CACHE_TTL_SECONDS]);
        } catch (\Throwable $e) {
            $this->logger->warning('Nie udało się zapisać cache rejestru.', ['error' => $e->getMessage()]);
        }
    }

    /** @return array{0: array<string, mixed>, 1: ?int}|null dane i chwila pobrania (null w starym formacie) */
    private function cacheGet(string $nip): ?array
    {
        try {
            $raw = $this->redis->get(self::cacheKey($nip));
        } catch (\Throwable $e) {
            $this->logger->warning('Nie udało się odczytać cache rejestru.', ['error' => $e->getMessage()]);

            return null;
        }

        return \is_string($raw) ? self::decodeCache($raw) : null;
    }

    /**
     * Wpis cache rejestru: `{fetched_at, data}` albo stary format (same dane, bez chwili pobrania).
     * Czyta go też backfill przebudowy.
     *
     * @return array{0: array<string, mixed>, 1: ?int}|null dane i chwila pobrania (null w starym formacie)
     */
    public static function decodeCache(string $raw): ?array
    {
        $entry = json_decode($raw, associative: true);
        if (! \is_array($entry)) {
            return null;
        }
        if (isset($entry['fetched_at'], $entry['data']) && \is_int($entry['fetched_at']) && \is_array($entry['data'])) {
            return [$entry['data'], $entry['fetched_at']];
        }

        return [$entry, null];
    }

    public static function cacheKey(string $nip): string
    {
        return 'seller360:registry:'.$nip;
    }
}
