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
 * awarię procesu, jest dołączany do następnego przebiegu.
 */
class OffersSectionFlusher implements Seller360Job
{
    public const DIRTY_KEY = 'seller360:dirty:offers';

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
        if ($this->redis->exists(self::DIRTY_KEY)) {
            if ($this->redis->exists(self::WORK_KEY)) {
                // Pozostałość po przerwanym przebiegu: dołączamy nowe wpisy do niej.
                $this->redis->sUnionStore(self::WORK_KEY, self::WORK_KEY, self::DIRTY_KEY);
                $this->redis->del(self::DIRTY_KEY);
            } else {
                $this->redis->rename(self::DIRTY_KEY, self::WORK_KEY);
            }
        }

        $members = $this->redis->sMembers(self::WORK_KEY);
        if ($members === false || $members === []) {
            $this->redis->del(self::WORK_KEY);

            return 0;
        }

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

            $computedAt = new \DateTimeImmutable($stats['computed_at']);
            $items[] = [
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

        $written = 0;
        try {
            $results = $this->writer->applyMany($items);
        } catch (\Throwable $e) {
            $this->requeue([...$retry, ...array_column($items, 'sellerId')]);
            $this->redis->del(self::WORK_KEY);
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
        $this->redis->del(self::WORK_KEY);

        return $written;
    }

    /** @param list<int> $sellerIds */
    private function requeue(array $sellerIds): void
    {
        foreach ($sellerIds as $id) {
            $this->redis->sAdd(self::DIRTY_KEY, (string) $id);
        }
    }
}
