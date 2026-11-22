<?php

namespace App\Seller360;

use Psr\Log\LoggerInterface;

/**
 * Koalescencja zdarzeń `offer.*` w sekcję `offers` (ETAP 13).
 *
 * Projektor tylko dopisuje `seller_id` do zbioru `seller360:dirty:offers`. Tu zbiór jest zdejmowany
 * atomowo przez `RENAME` na klucz roboczy (zdarzenia z trakcji lecą do świeżego zbioru), każdy
 * sprzedawca dostaje JEDNO zapytanie o statystyki, niezależnie od liczby zdarzeń. Sprzedawca,
 * dla którego pobranie albo zapis się nie udały, wraca do zbioru. Klucz roboczy, który przetrwał
 * awarię procesu albo limit 500 sprzedawców, jest przetwarzany w pierwszej kolejności (bez scalania z DIRTY).
 */
class OffersSectionFlusher implements Seller360Job
{
    public const DIRTY_KEY = 'seller360:dirty:offers';

    /** Maksymalna liczba sprzedawców w jednym przebiegu. */
    public const BATCH_LIMIT = 500;

    private const WORK_KEY = 'seller360:dirty:offers:flushing';

    public function __construct(
        private readonly \Redis $redis,
        private readonly CatalogSellerClient $catalog,
        private readonly SectionWriter $writer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function name(): string
    {
        return 'offers:flush';
    }

    public function intervalSeconds(): int
    {
        return 30;
    }

    public function run(): void
    {
        $this->flush();
    }

    /** @return int liczba zapisanych sekcji (`created|updated`) */
    public function flush(): int
    {
        // Pozostałość po przerwanym przebiegu (albo po przekroczeniu limitu) przetwarzamy w pierwszej
        // kolejności; zbiór DIRTY zostaje nietknięty do następnego cyklu (bez scalania, więc bez wyścigu z SADD).
        if (! $this->redis->exists(self::WORK_KEY)) {
            try {
                if (! $this->redis->exists(self::DIRTY_KEY) || $this->redis->rename(self::DIRTY_KEY, self::WORK_KEY) === false) {
                    return 0;
                }
            } catch (\RedisException) {
                // Klucz zniknął między EXISTS a RENAME (równoległy flusher): pusty cykl.
                return 0;
            }
        }

        $members = $this->redis->sMembers(self::WORK_KEY);
        if ($members === false || $members === []) {
            $this->redis->del(self::WORK_KEY);

            return 0;
        }
        // Jeden przebieg obejmuje najwyżej BATCH_LIMIT sprzedawców (mieści się w stop_grace_period);
        // reszta czeka w kluczu roboczym na następny cykl.
        $members = \array_slice($members, 0, self::BATCH_LIMIT);

        $items = [];
        $retry = [];
        foreach ($members as $member) {
            $sellerId = (int) $member;
            try {
                $stats = $this->catalog->offerStats($sellerId);
            } catch (\Throwable $e) {
                $this->logger->warning('offer-stats niedostępne, sprzedawca wraca do zbioru.', ['seller_id' => $sellerId, 'error' => $e->getMessage()]);
                $retry[] = $sellerId;

                continue;
            }
            if ($stats === null) {
                $this->logger->warning('offer-stats: brak sprzedawcy w catalogu, pomijam.', ['seller_id' => $sellerId]);

                continue;
            }

            // Nieparsowalne `computed_at` to błąd jednego sprzedawcy: wraca do zbioru brudnych, a klucz roboczy
            // i tak zostaje zdjęty (`finish`), więc jedna zła wartość nie blokuje całej kolejki.
            try {
                $items[] = $this->item($sellerId, $stats);
            } catch (\Throwable $e) {
                $this->logger->warning('offer-stats: nieparsowalne computed_at, sprzedawca wraca do zbioru.', ['seller_id' => $sellerId, 'computed_at' => $stats['computed_at'] ?? null, 'error' => $e->getMessage()]);
                $retry[] = $sellerId;
            }
        }

        $written = 0;
        try {
            $results = $this->writer->applyMany($items);
        } catch (\Throwable $e) {
            $this->requeue([...$retry, ...array_column($items, 'sellerId')]);
            $this->finish($members);
            throw $e;
        }
        foreach ($items as $i => $item) {
            $result = $results[$i] ?? 'error';
            if ($result === 'error') {
                $retry[] = $item['sellerId'];
            } elseif ($result !== 'noop') {
                $written++;
            }
        }

        $this->requeue($retry);
        $this->finish($members);

        return $written;
    }

    /**
     * Przeliczenie sekcji `offers` dla podanych sprzedawców, z pominięciem zbioru brudnych (backfill przebudowy).
     * Statystyki pobierane równolegle; `$writer` pozwala skierować zapis na wybrane indeksy (domyślnie writer usługi).
     * Błąd pobrania lub zapisu leci wyjątkiem — przebudowa i tak się wtedy przerywa.
     *
     * @param  list<int>  $sellerIds
     * @return int liczba zapisanych sekcji (`created|updated`)
     */
    public function refreshSellers(array $sellerIds, ?SectionWriter $writer = null): int
    {
        $writer ??= $this->writer;
        $written = 0;
        foreach (array_chunk(array_values(array_unique($sellerIds)), self::BATCH_LIMIT) as $chunk) {
            $items = [];
            foreach ($this->catalog->offerStatsMany($chunk) as $sellerId => $stats) {
                if ($stats !== null) {
                    $items[] = $this->item($sellerId, $stats);
                }
            }
            foreach ($writer->applyMany($items) as $result) {
                if ($result === 'error') {
                    throw new \RuntimeException('Zapis sekcji offers odrzucony przez ES.');
                }
                if ($result !== 'noop') {
                    $written++;
                }
            }
        }

        return $written;
    }

    /**
     * @param  array{active: int, suspended: int, sold_out: int, total: int, computed_at: string}  $stats
     * @return array{sellerId: int, section: string, data: array<string, mixed>, meta: SectionMeta, docFields: array<string, mixed>}
     */
    private function item(int $sellerId, array $stats): array
    {
        $computedAt = new \DateTimeImmutable($stats['computed_at']);

        return [
            'sellerId' => $sellerId,
            'section' => 'offers',
            'data' => [
                'active' => (int) $stats['active'],
                'suspended' => (int) $stats['suspended'],
                'sold_out' => (int) $stats['sold_out'],
                'total' => (int) $stats['total'],
                'computed_at' => $stats['computed_at'],
            ],
            'meta' => new SectionMeta((int) $computedAt->format('Uv'), $computedAt),
            'docFields' => [],
        ];
    }

    /** Zdejmuje przetworzonych z klucza roboczego (pusty zbiór znika w Redisie). @param list<string> $members */
    private function finish(array $members): void
    {
        $this->redis->sRem(self::WORK_KEY, ...$members);
    }

    /** @param list<int> $sellerIds */
    private function requeue(array $sellerIds): void
    {
        foreach ($sellerIds as $id) {
            $this->redis->sAdd(self::DIRTY_KEY, (string) $id);
        }
    }
}
