<?php

namespace App\Analytics;

use App\Service\IndexAliases;
use App\Service\TaskWaiter;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;

/**
 * Przeliczenie popularności produktów (ETAP 12, spec §6–7) — cztery kroki:
 *
 * 1. `p(k)` z agregacji kliknięć i wyświetleń w oknie `[as_of − dni, as_of)`
 *    (pozycje 1..20 — tyle wyświetleń zapisuje handler) i globalny CTR `c̄`.
 * 2. Transform wsadowy `product-popularity` usunięty i odtworzony z tym oknem:
 *    pivot po `product.id` przez OBA źródła (wyświetlenia + kliknięcia),
 *    waga kliknięcia `1/p(k)` z pola runtime, formuły jako `bucket_script`.
 *    Transform ciągły nie „zapomina” zdarzeń wychodzących z okna — przy oknie
 *    przesuwnym prościej przeliczyć całość. Cel to `product-popularity` po
 *    `_delete_by_query`: nazwy indeksu źródłowego polityki enrich nie da się
 *    zmienić, a do `_execute` żywy pipeline czyta własną kopię (`.enrich-*`),
 *    więc chwilowo pusty indeks nikogo nie dotyka.
 * 3. `_execute` polityki enrich (czekamy na koniec) — nowa kopia dla pipeline'u.
 * 4. `_update_by_query` z `pipeline=products-enrich-popularity` w tle, tylko po
 *    dokumentach, które coś zmienią: produkty z nowego wyniku + te, które mają
 *    jeszcze stare pola. Reszta przeszłaby przez pipeline bez zmian.
 *    Wersje: UBQ zapisuje V+1 wewnętrznie, wersja zewnętrzna to `projected_at_us`
 *    (~1,7e15) — kolejność strumienia nienaruszona (jak `SellerRenamer`).
 *
 * Definicje w kodzie, nie w JSON-ie: obraz produkcyjny search kopiuje tylko
 * `infra/elasticsearch/mappings`, a ciało transformu i tak zależy od `p(k)`.
 */
final class PopularityRefresher
{
    public const TRANSFORM = 'product-popularity';
    public const INDEX = 'product-popularity';
    public const POLICY = 'product-popularity-policy';
    public const PIPELINE = 'products-enrich-popularity';
    public const VARIANTS = ['ips', 'raw'];

    /** Handler zapisuje wyświetlenia top 20; kliknięcia dalej (przewijanie) liczą się z p(20). */
    public const POSITIONS = 20;

    private const CLICK = 'user.clicked_result';

    /** Powyżej — UBQ po całym indeksie zamiast listy `ids` (limit terminów w zapytaniu). */
    private const MAX_IDS = 50_000;

    private const TRANSFORM_TIMEOUT_SECONDS = 600;

    public function __construct(
        private readonly Client $client,
        private readonly TaskWaiter $waiter,
        private readonly string $clicksIndex = 'events-user-*',
        private readonly string $impressionsIndex = 'events-impression-*',
        private readonly string $popularityIndex = self::INDEX,
        private readonly string $transformId = self::TRANSFORM,
        private readonly string $policy = self::POLICY,
        private readonly string $pipeline = self::PIPELINE,
        private readonly string $productsIndex = IndexAliases::WRITE,
        private readonly float $pollSeconds = 1.0,
    ) {
    }

    /**
     * @param float                                $rps          `requests_per_second` dla UBQ (-1 = bez limitu)
     * @param ?callable(string): void              $onStep       nazwa kroku, zanim się zacznie
     * @param ?callable(array<string, mixed>): void $onProgress  `task.status` UBQ
     * @param ?callable(): bool                    $shouldCancel sprawdzane przy odpytywaniu UBQ (Ctrl-C)
     *
     * @throws PopularityRefused przed jakimkolwiek zapisem: puste okno, brak kliknięć, brak indeksu
     */
    public function refresh(
        \DateTimeImmutable $asOf,
        int $windowDays,
        float $alpha,
        string $variant,
        float $rps,
        ?callable $onStep = null,
        ?callable $onProgress = null,
        ?callable $shouldCancel = null,
    ): PopularityRefreshed {
        if (! \in_array($variant, self::VARIANTS, true) || $windowDays < 1) {
            throw new \InvalidArgumentException("Wariant raw|ips i okno ≥ 1 dzień (podano {$variant}, {$windowDays}).");
        }
        $onStep ??= static function (string $step): void {};
        $timings = [];
        $clock = static function (string $step, \Closure $work) use (&$timings, $onStep): mixed {
            $onStep($step);
            $start = microtime(true);
            $result = $work();
            $timings[$step] = microtime(true) - $start;

            return $result;
        };

        $to = $asOf->setTimezone(new \DateTimeZone('UTC'));
        $from = $to->modify("-{$windowDays} days");
        $window = ['gte' => self::iso($from), 'lt' => self::iso($to)];

        [$counts, $propensity, $meanCtr] = $clock('p(k)', fn () => $this->propensity($window));
        $formula = new PopularityFormula($meanCtr, $alpha);

        if (! $this->client->indices()->exists(['index' => $this->popularityIndex])->asBool()) {
            throw new PopularityRefused("Brak indeksu {$this->popularityIndex} — najpierw make analytics-setup (tworzy go z mapowaniem i wykonuje politykę).");
        }

        $products = $clock('transform', fn () => $this->rebuild($window, $propensity, $formula, $variant));
        $clock('enrich _execute', fn () => $this->client->enrich()->executePolicy(['name' => $this->policy, 'wait_for_completion' => true]));

        $ids = $this->productIds();
        $outcome = $clock('update_by_query', fn () => $this->updateProducts($ids, $rps, $onProgress, $shouldCancel));

        return new PopularityRefreshed(
            from: self::iso($from),
            to: self::iso($to),
            counts: $counts,
            propensity: $propensity,
            meanCtr: $meanCtr,
            products: $products,
            ubq: $outcome['result'],
            cancelled: $outcome['cancelled'],
            ubqError: $outcome['error'],
            timings: $timings,
        );
    }

    /**
     * Krok 1: liczniki wg pozycji → p(k) i c̄ (kliknięcia 1..20 / wyświetlenia).
     *
     * @param array{gte: string, lt: string} $window
     *
     * @return array{0: array<int, array{impressions:int, clicks:int}>, 1: array<int, float>, 2: float}
     */
    private function propensity(array $window): array
    {
        $aggs = $this->client->search([
            'index' => "{$this->impressionsIndex},{$this->clicksIndex}",
            'body' => [
                'size' => 0,
                'track_total_hits' => false,
                'query' => ['range' => ['@timestamp' => $window]],
                'aggs' => [
                    'impressions' => [
                        'filter' => ['exists' => ['field' => 'impression.position']],
                        'aggs' => ['by_position' => ['terms' => ['field' => 'impression.position', 'size' => self::POSITIONS]]],
                    ],
                    'clicks' => [
                        'filter' => ['bool' => ['filter' => [
                            ['term' => ['event.type' => self::CLICK]],
                            ['range' => ['click.position' => ['gte' => 1, 'lte' => self::POSITIONS]]],
                        ]]],
                        'aggs' => ['by_position' => ['terms' => ['field' => 'click.position', 'size' => self::POSITIONS]]],
                    ],
                ],
            ],
        ])->asArray()['aggregations'];

        $counts = array_fill(1, self::POSITIONS, ['impressions' => 0, 'clicks' => 0]);
        foreach (['impressions', 'clicks'] as $kind) {
            foreach ($aggs[$kind]['by_position']['buckets'] as $bucket) {
                $k = (int) $bucket['key'];
                if ($k >= 1 && $k <= self::POSITIONS) {
                    $counts[$k][$kind] = (int) $bucket['doc_count'];
                }
            }
        }

        $impressions = array_sum(array_column($counts, 'impressions'));
        $clicks = array_sum(array_column($counts, 'clicks'));
        if ($impressions === 0) {
            throw new PopularityRefused("Okno [{$window['gte']}, {$window['lt']}): brak wyświetleń — nie ma z czego liczyć popularności; nic nie zapisano.");
        }
        if ($clicks === 0) {
            throw new PopularityRefused("Okno [{$window['gte']}, {$window['lt']}): {$impressions} wyświetleń, ale brak kliknięć na pozycjach 1..".self::POSITIONS.' — c̄ = 0; nic nie zapisano.');
        }

        try {
            $propensity = PositionPropensity::fromCounts($counts);
        } catch (\InvalidArgumentException $e) {
            throw new PopularityRefused($e->getMessage().' Nic nie zapisano.', previous: $e);
        }

        return [$counts, $propensity, $clicks / $impressions];
    }

    /**
     * Krok 2: transform wsadowy od zera → `product-popularity` z samymi produktami okna.
     *
     * @param array{gte: string, lt: string} $window
     * @param array<int, float>              $propensity
     *
     * @return int liczba produktów w wyniku
     */
    private function rebuild(array $window, array $propensity, PopularityFormula $formula, string $variant): int
    {
        try {
            $this->client->transform()->deleteTransform(['transform_id' => $this->transformId, 'force' => true]);
        } catch (ClientResponseException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }

        $this->client->deleteByQuery([
            'index' => $this->popularityIndex,
            'conflicts' => 'proceed',
            'refresh' => true,
            'body' => ['query' => ['match_all' => new \stdClass()]],
        ]);

        $this->client->transform()->putTransform([
            'transform_id' => $this->transformId,
            'body' => $this->transformBody($window, $propensity, $formula, $variant),
        ]);
        $this->client->transform()->startTransform(['transform_id' => $this->transformId]);
        $this->waitForTransform();

        $this->client->indices()->refresh(['index' => $this->popularityIndex]);

        return (int) $this->client->count(['index' => $this->popularityIndex])->asArray()['count'];
    }

    /**
     * @param array{gte: string, lt: string} $window
     * @param array<int, float>              $propensity
     *
     * @return array<string, mixed>
     */
    private function transformBody(array $window, array $propensity, PopularityFormula $formula, string $variant): array
    {
        $raw = $formula->bucketScript('clicks._count');
        $ips = $formula->bucketScript('clicks_ips');

        return [
            'description' => "ETAP 12: popularność produktów, okno [{$window['gte']}, {$window['lt']}), α={$formula->alpha}, c̄={$formula->meanCtr}, popularity={$variant}. Wsadowy — odtwarzany przez search:popularity:refresh.",
            'source' => [
                'index' => [$this->clicksIndex, $this->impressionsIndex],
                'query' => ['bool' => [
                    'filter' => [
                        ['range' => ['@timestamp' => $window]],
                        ['exists' => ['field' => 'product.id']],
                    ],
                    'should' => [
                        ['term' => ['event.type' => self::CLICK]],
                        ['exists' => ['field' => 'impression.position']],
                    ],
                    'minimum_should_match' => 1,
                ]],
                // Waga kliknięcia 1/p(k); pozycje > 20 (przewijanie, bez wyświetlenia) z p(20).
                // Dokumenty wyświetleń nie mają click.position — containsKey, bo doc['…']
                // dla pola niezmapowanego w danym indeksie rzuca wyjątek.
                'runtime_mappings' => ['click_weight' => [
                    'type' => 'double',
                    'script' => [
                        'lang' => 'painless',
                        'source' => <<<'PAINLESS'
                            if (doc.containsKey('click.position') && doc['click.position'].size() > 0) {
                                int k = (int) doc['click.position'].value;
                                int n = params.weights.size();
                                emit(params.weights[k < 1 ? 0 : (k > n ? n - 1 : k - 1)]);
                            }
                            PAINLESS,
                        'params' => ['weights' => array_values(array_map(static fn (float $p): float => 1.0 / $p, $propensity))],
                    ],
                ]],
            ],
            'dest' => ['index' => $this->popularityIndex],
            'pivot' => [
                'group_by' => ['product_id' => ['terms' => ['field' => 'product.id']]],
                'aggregations' => [
                    'impressions' => ['filter' => ['exists' => ['field' => 'impression.position']]],
                    'clicks' => ['filter' => ['term' => ['event.type' => self::CLICK]]],
                    'clicks_ips' => ['sum' => ['field' => 'click_weight']],
                    'popularity_raw' => ['bucket_script' => $raw],
                    'popularity_ips' => ['bucket_script' => $ips],
                    'popularity' => ['bucket_script' => $variant === 'raw' ? $raw : $ips],
                ],
            ],
            'settings' => ['max_page_search_size' => 5000],
        ];
    }

    /** Transform wsadowy sam przechodzi w `stopped` po pierwszym checkpoincie. */
    private function waitForTransform(): void
    {
        $deadline = microtime(true) + self::TRANSFORM_TIMEOUT_SECONDS;
        while (true) {
            $stats = $this->client->transform()->getTransformStats(['transform_id' => $this->transformId])->asArray()['transforms'][0] ?? [];
            $state = $stats['state'] ?? 'unknown';
            if ($state === 'failed') {
                throw new \RuntimeException("Transform {$this->transformId} padł: ".($stats['reason'] ?? 'bez powodu'));
            }
            if ($state === 'stopped' && ($stats['checkpointing']['last']['checkpoint'] ?? 0) >= 1) {
                return;
            }
            if (microtime(true) > $deadline) {
                throw new \RuntimeException("Transform {$this->transformId} nie skończył w ".self::TRANSFORM_TIMEOUT_SECONDS." s (stan: {$state}).");
            }
            usleep((int) ($this->pollSeconds * 1_000_000));
        }
    }

    /** @return list<string> product_id z nowego wyniku */
    private function productIds(): array
    {
        $ids = [];
        $after = null;
        do {
            $body = ['size' => 10_000, 'sort' => [['product_id' => 'asc']], '_source' => ['product_id'], 'track_total_hits' => false];
            if ($after !== null) {
                $body['search_after'] = $after;
            }
            $hits = $this->client->search(['index' => $this->popularityIndex, 'body' => $body])->asArray()['hits']['hits'];
            foreach ($hits as $hit) {
                $ids[] = (string) $hit['_source']['product_id'];
            }
            $after = $hits === [] ? null : end($hits)['sort'];
        } while ($hits !== []);

        return $ids;
    }

    /**
     * Krok 4. Zmienia się tylko produkt z nowym wpisem albo ze starymi polami
     * (pipeline najpierw je kasuje) — `popularity` ustawia się tylko razem
     * z `popularity_raw`/`popularity_ips`, więc `exists` na nich wystarcza.
     *
     * @param list<string> $ids
     *
     * @return array{cancelled: bool, result: array<string, mixed>, error: ?array<string, mixed>}
     */
    private function updateProducts(array $ids, float $rps, ?callable $onProgress, ?callable $shouldCancel): array
    {
        $query = \count($ids) > self::MAX_IDS
            ? ['match_all' => new \stdClass()]
            : ['bool' => ['should' => [
                ['ids' => ['values' => $ids]],
                ['exists' => ['field' => 'popularity_raw']],
                ['exists' => ['field' => 'popularity_ips']],
            ], 'minimum_should_match' => 1]];

        // UBQ czyta przez search: zapisy sprzed chwili, jeszcze nieodświeżone, by pominął.
        $this->client->indices()->refresh(['index' => $this->productsIndex]);

        $taskId = (string) $this->client->updateByQuery([
            'index' => $this->productsIndex,
            'pipeline' => $this->pipeline,
            'conflicts' => 'proceed',
            'wait_for_completion' => false,
            'requests_per_second' => $rps,
            'refresh' => true,
            'body' => ['query' => $query],
        ])->asArray()['task'];

        return $this->waiter->wait(
            $taskId,
            $onProgress ?? static function (array $status): void {},
            $shouldCancel ?? static fn (): bool => false,
        );
    }

    private static function iso(\DateTimeImmutable $at): string
    {
        return $at->format('Y-m-d\TH:i:s\Z');
    }
}
