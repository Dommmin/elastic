<?php

namespace App\Seller360;

use Elastic\Elasticsearch\Client;
use Psr\Log\LoggerInterface;

/**
 * Przeliczenie sekcji `sales` z transformu `seller-sales-daily` (ETAP 13).
 *
 * Jedno zapytanie z agregacją composite po `seller_id` (stronicowane `after_key`) i dwoma filtrowanymi
 * podagregacjami: okno bieżące `[now-30d, now)` i poprzednie `[now-60d, now-30d)`. Sekcję dostają tylko
 * sprzedawcy, którzy mają wiersze w oknie 60 dni; brak sekcji obsługuje odczyt (Task 12).
 * Konwersja to `order_sessions / cart_sessions` okna, `null` przy zerze sesji koszyka.
 * `conversion_drop = (prev - cur) / prev` liczymy tylko przy `cart_sessions_prev >= 20` i obu konwersjach.
 */
class SalesSectionRefresher implements Seller360Job
{
    /** Minimalna liczba sesji koszyka w poprzednim oknie, by spadek konwersji coś znaczył. */
    public const MIN_PREV_CART_SESSIONS = 20;

    private const PAGE_SIZE = 500;

    public function __construct(
        private readonly Client $client,
        private readonly SectionWriter $writer,
        private readonly LoggerInterface $logger,
        private readonly string $index = 'seller-sales-daily',
    ) {
    }

    public function name(): string
    {
        return 'sales:refresh';
    }

    public function intervalSeconds(): int
    {
        return 300;
    }

    public function run(): void
    {
        $this->refresh(new \DateTimeImmutable);
    }

    /**
     * @param  SectionWriter|null  $writer  inny cel zapisu (backfill przebudowy kieruje go na nowe indeksy)
     * @return int liczba zapisanych sekcji (`created|updated`)
     */
    public function refresh(\DateTimeImmutable $now, ?SectionWriter $writer = null): int
    {
        $writer ??= $this->writer;
        $now = $now->setTimezone(new \DateTimeZone('UTC'));
        $mid = $now->modify('-30 days');
        $from = $now->modify('-60 days');
        $version = (int) $now->format('Uv');

        $written = 0;
        $after = null;
        do {
            $composite = ['size' => self::PAGE_SIZE, 'sources' => [['seller_id' => ['terms' => ['field' => 'seller_id']]]]];
            if ($after !== null) {
                $composite['after'] = $after;
            }
            $response = $this->client->search([
                'index' => $this->index,
                'body' => [
                    'size' => 0,
                    'query' => ['range' => ['day' => ['gte' => $this->iso($from), 'lt' => $this->iso($now)]]],
                    'aggs' => ['sellers' => [
                        'composite' => $composite,
                        'aggs' => [
                            'cur' => ['filter' => ['range' => ['day' => ['gte' => $this->iso($mid), 'lt' => $this->iso($now)]]], 'aggs' => $this->sums(true)],
                            'prev' => ['filter' => ['range' => ['day' => ['gte' => $this->iso($from), 'lt' => $this->iso($mid)]]], 'aggs' => $this->sums(false)],
                        ],
                    ]],
                ],
            ])->asArray();

            $agg = $response['aggregations']['sellers'] ?? [];
            $buckets = $agg['buckets'] ?? [];
            $after = $agg['after_key'] ?? null;

            $items = [];
            foreach ($buckets as $bucket) {
                $sellerId = $bucket['key']['seller_id'] ?? null;
                if (! \is_string($sellerId) || ! ctype_digit($sellerId)) {
                    $this->logger->warning('seller-sales-daily: nienumeryczny seller_id, pomijam.', ['seller_id' => $sellerId]);

                    continue;
                }
                $items[] = [
                    'sellerId' => (int) $sellerId,
                    'section' => 'sales',
                    'data' => $this->section($bucket),
                    'meta' => new SectionMeta($version, $now),
                    'docFields' => [],
                ];
            }

            foreach ($writer->applyMany($items) as $result) {
                if ($result === 'created' || $result === 'updated') {
                    $written++;
                }
            }
        } while ($buckets !== [] && $after !== null);

        return $written;
    }

    /** @return array<string, mixed> */
    private function sums(bool $withGmv): array
    {
        $aggs = [
            'cart' => ['sum' => ['field' => 'cart_sessions']],
            'orders' => ['sum' => ['field' => 'order_sessions']],
        ];
        if ($withGmv) {
            $aggs['gmv'] = ['sum' => ['field' => 'gmv_cents']];
        }

        return $aggs;
    }

    /**
     * @param  array<string, mixed>  $bucket
     * @return array<string, mixed>
     */
    private function section(array $bucket): array
    {
        $curCart = (int) ($bucket['cur']['cart']['value'] ?? 0);
        $curOrders = (int) ($bucket['cur']['orders']['value'] ?? 0);
        $prevCart = (int) ($bucket['prev']['cart']['value'] ?? 0);
        $prevOrders = (int) ($bucket['prev']['orders']['value'] ?? 0);

        $cur = $curCart > 0 ? $curOrders / $curCart : null;
        $prev = $prevCart > 0 ? $prevOrders / $prevCart : null;
        $drop = ($prev !== null && $prev > 0 && $cur !== null && $prevCart >= self::MIN_PREV_CART_SESSIONS)
            ? round(($prev - $cur) / $prev, 6)
            : null;

        return [
            'gmv_30d_cents' => (int) ($bucket['cur']['gmv']['value'] ?? 0),
            'orders_30d' => $curOrders,
            'cart_sessions_30d' => $curCart,
            'cart_sessions_prev_30d' => $prevCart,
            'conversion_30d' => $cur,
            'conversion_prev_30d' => $prev,
            'conversion_drop' => $drop,
        ];
    }

    private function iso(\DateTimeImmutable $at): string
    {
        return $at->format('Y-m-d\TH:i:s\Z');
    }
}
