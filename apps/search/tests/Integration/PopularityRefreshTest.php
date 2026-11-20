<?php

namespace App\Tests\Integration;

use App\Analytics\PopularityRefreshed;
use App\Analytics\PopularityRefresher;
use App\Analytics\PopularityRefused;
use App\Service\TaskWaiter;
use App\Tests\Support\RealElasticsearch;
use Elastic\Elasticsearch\Client;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * `search:popularity:refresh` od zdarzeń do `products-*` na PRAWDZIWYM
 * Elasticsearchu: p(k) z agregacji, transform wsadowy, `_execute` polityki,
 * `_update_by_query` z pipeline'em. Wszystkie nazwy jednorazowe (`*-test-<uniqid>`,
 * zdarzenia w zwykłych indeksach `products-poptest-*`, bo nazwy `events-*-*`
 * pasują do szablonów data streamów i do ciągłego transformu search-stats-daily).
 * Działa jako `searchsvc`, więc sprawdza też uprawnienia roli search_service.
 *
 * Dane ułożone tak, żeby wynik dało się policzyć ręcznie:
 * pozycja 1: 10 wyświetleń, 2 kliknięcia (produkt 1); pozycja 2: 10 wyświetleń,
 * 1 kliknięcie (produkt 2) → p(2) = 0,5; pozycje 3–20 bez wyświetleń dziedziczą 0,5.
 * Produkt 3: jedno kliknięcie na pozycji 25 (nieskończone przewijanie, bez
 * wyświetlenia) → waga 1/p(20) = 2. c̄ = 3/20 = 0,15 (kliknięcia 1..20 / wyświetlenia).
 */
#[Group('integration')]
final class PopularityRefreshTest extends TestCase
{
    use RealElasticsearch;

    private const ANALYTICS_DIR = '/infra/elasticsearch/analytics';
    private const VERSION = 1_790_000_000_000_000;
    private const AS_OF = '2026-11-20T00:00:00Z';
    private const INSIDE = '2026-11-10T12:00:00Z';

    private Client $client;
    private string $suffix;
    private string $clicks;
    private string $impressions;
    private string $source;
    private string $policy;
    private string $pipeline;
    private string $products;

    protected function setUp(): void
    {
        $this->client = $this->elasticsearchOrSkip();
        if (! is_dir(self::ANALYTICS_DIR)) {
            self::markTestSkipped('Brak katalogu '.self::ANALYTICS_DIR.' (montowany w kontenerze search-consumer).');
        }

        $this->suffix = uniqid();
        $this->clicks = "products-poptest-{$this->suffix}-user";
        $this->impressions = "products-poptest-{$this->suffix}-impr";
        $this->source = "product-popularity-test-{$this->suffix}";
        $this->policy = "product-popularity-test-{$this->suffix}";
        $this->pipeline = "products-enrich-popularity-test-{$this->suffix}";
        $this->products = "products-poptest-{$this->suffix}";
    }

    protected function tearDown(): void
    {
        if (! isset($this->suffix)) {
            return;
        }
        $this->quietly(fn () => $this->client->transform()->deleteTransform(['transform_id' => $this->source, 'force' => true]));
        // Kolejność wymuszona przez ES: indeks z default_pipeline → pipeline → polityka → źródło.
        $this->client->indices()->delete(['index' => $this->products, 'ignore_unavailable' => true]);
        $this->quietly(fn () => $this->client->ingest()->deletePipeline(['id' => $this->pipeline]));
        $this->quietly(fn () => $this->client->enrich()->deletePolicy(['name' => $this->policy]));
        foreach ([$this->source, $this->clicks, $this->impressions] as $index) {
            $this->client->indices()->delete(['index' => $index, 'ignore_unavailable' => true]);
        }
    }

    public function test_refresh_ips_liczy_p_k_i_dokleja_popularnosc_do_produktow(): void
    {
        $this->setUpWorld();

        $result = $this->refresh('ips');

        self::assertSame('2026-10-21T00:00:00Z', $result->from);
        self::assertSame('2026-11-20T00:00:00Z', $result->to);
        self::assertEqualsWithDelta(0.15, $result->meanCtr, 1e-12);
        self::assertEqualsWithDelta(1.0, $result->propensity[1], 1e-12);
        self::assertEqualsWithDelta(0.5, $result->propensity[2], 1e-12);
        self::assertEqualsWithDelta(0.5, $result->propensity[20], 1e-12);
        self::assertSame(['impressions' => 10, 'clicks' => 2], $result->counts[1]);
        self::assertSame(3, $result->products);

        // product-popularity = dokładnie produkty z okna; stary wpis 999 zniknął.
        self::assertSame(['1', '2', '3'], $this->popularityIds());
        $row = $this->popularityRow('2');
        self::assertSame(10, $row['impressions']);
        self::assertSame(1, $row['clicks']);

        $two = $this->productSource('2');
        self::assertEqualsWithDelta(5 / 30, $two['popularity'], 1e-6, '(1/0,5 + 20·0,15) / (10 + 20)');
        self::assertEqualsWithDelta(4 / 30, $two['popularity_raw'], 1e-6, '(1 + 20·0,15) / (10 + 20)');
        self::assertEqualsWithDelta(5 / 30, $two['popularity_ips'], 1e-6);

        $three = $this->productSource('3');
        self::assertEqualsWithDelta(0.25, $three['popularity'], 1e-6, 'kliknięcie spoza top 20 z wagą 1/p(20)');
        self::assertEqualsWithDelta(0.2, $three['popularity_raw'], 1e-6);

        self::assertEqualsWithDelta(5 / 30, $this->productSource('1')['popularity'], 1e-6);

        foreach (['popularity', 'popularity_raw', 'popularity_ips'] as $field) {
            self::assertArrayNotHasKey($field, $this->productSource('999'), 'produkt spoza okna traci starą popularność');
        }

        // UBQ tylko po dokumentach, które coś zmieniają: 1, 2, 3 (nowe wpisy) + 999 (stary).
        self::assertSame(4, $result->ubq['updated'] ?? null);
        self::assertSame(0, $result->ubq['version_conflicts'] ?? null);
        self::assertSame(self::VERSION + 999 + 1, $this->client->get(['index' => $this->products, 'id' => '999'])['_version'],
            'UBQ: wersja wewnętrzna V+1 — strumień z projected_at_us dalej wygrywa');
    }

    public function test_wariant_raw_przepisuje_do_popularity_wartosc_surowa(): void
    {
        $this->setUpWorld();

        $this->refresh('ips');
        self::assertEqualsWithDelta(5 / 30, $this->productSource('2')['popularity'], 1e-6);

        $this->refresh('raw');
        $two = $this->productSource('2');
        self::assertEqualsWithDelta(4 / 30, $two['popularity'], 1e-6);
        self::assertEqualsWithDelta(4 / 30, $two['popularity_raw'], 1e-6);
        self::assertEqualsWithDelta(5 / 30, $two['popularity_ips'], 1e-6, 'oba warianty zostają do wglądu');
        self::assertEqualsWithDelta(0.2, $this->productSource('3')['popularity'], 1e-6);
    }

    public function test_puste_okno_odmawia_i_niczego_nie_zapisuje(): void
    {
        $this->setUpWorld();

        try {
            $this->refresher()->refresh(new \DateTimeImmutable('2020-01-01T00:00:00Z'), 30, 20.0, 'ips', -1);
            self::fail('Refresh przy pustym oknie przeszedł.');
        } catch (PopularityRefused $e) {
            self::assertStringContainsString('brak wyświetleń', $e->getMessage());
        }

        self::assertSame(['999'], $this->popularityIds(), 'stary wpis nietknięty');
        self::assertEqualsWithDelta(0.9, $this->productSource('999')['popularity'], 1e-6);
        self::assertSame(0, (int) $this->client->transform()->getTransform(['transform_id' => $this->source.'*', 'allow_no_match' => true])['count']);
    }

    // --- klocki -------------------------------------------------------------

    private function refresh(string $variant): PopularityRefreshed
    {
        return $this->refresher()->refresh(new \DateTimeImmutable(self::AS_OF), 30, 20.0, $variant, -1);
    }

    private function refresher(): PopularityRefresher
    {
        return new PopularityRefresher(
            $this->client,
            new TaskWaiter($this->client, pollSeconds: 0.2),
            clicksIndex: $this->clicks,
            impressionsIndex: $this->impressions,
            popularityIndex: $this->source,
            transformId: $this->source,
            policy: $this->policy,
            pipeline: $this->pipeline,
            productsIndex: $this->products,
            pollSeconds: 0.2,
        );
    }

    /** Zdarzenia, źródło popularności ze starym wpisem 999, polityka, pipeline i produkty 1, 2, 3, 999. */
    private function setUpWorld(): void
    {
        $this->createEvents();

        $body = $this->analyticsJson('index-product-popularity.json');
        $body['settings']['number_of_replicas'] = 0;
        $this->client->indices()->create(['index' => $this->source, 'body' => $body]);
        $this->client->index(['index' => $this->source, 'id' => 'stary', 'refresh' => 'true', 'body' => [
            'product_id' => '999', 'popularity' => 0.9, 'popularity_raw' => 0.8, 'popularity_ips' => 0.9, 'impressions' => 1, 'clicks' => 1,
        ]]);

        $policy = $this->analyticsJson('enrich-policy-product-popularity.json');
        $policy['match']['indices'] = $this->source;
        $this->client->enrich()->putPolicy(['name' => $this->policy, 'body' => $policy]);
        $this->client->enrich()->executePolicy(['name' => $this->policy, 'wait_for_completion' => true]);

        $pipeline = $this->analyticsJson('pipeline-products-enrich-popularity.json');
        foreach ($pipeline['processors'] as &$processor) {
            if (isset($processor['enrich'])) {
                $processor['enrich']['policy_name'] = $this->policy;
            }
        }
        unset($processor);
        $this->client->ingest()->putPipeline(['id' => $this->pipeline, 'body' => $pipeline]);

        $mapping = $this->mappingBody(6);
        $mapping['settings']['index']['default_pipeline'] = $this->pipeline;
        $mapping['settings']['number_of_replicas'] = 0;
        $this->client->indices()->create(['index' => $this->products, 'body' => $mapping]);
        $this->bulkProducts($this->client, $this->products, 1, 3, self::VERSION);
        $this->bulkProducts($this->client, $this->products, 999, 999, self::VERSION);
        self::assertEqualsWithDelta(0.9, $this->productSource('999')['popularity'], 1e-6, 'przygotowanie: stara popularność w produkcie');
    }

    private function createEvents(): void
    {
        $this->client->indices()->create(['index' => $this->clicks, 'body' => [
            'settings' => ['number_of_replicas' => 0],
            'mappings' => ['dynamic' => 'strict', 'properties' => [
                '@timestamp' => ['type' => 'date'],
                'event' => ['properties' => ['type' => ['type' => 'keyword']]],
                'product' => ['properties' => ['id' => ['type' => 'keyword']]],
                'click' => ['properties' => ['position' => ['type' => 'integer']]],
            ]],
        ]]);
        $this->client->indices()->create(['index' => $this->impressions, 'body' => [
            'settings' => ['number_of_replicas' => 0],
            'mappings' => ['dynamic' => 'strict', 'properties' => [
                '@timestamp' => ['type' => 'date'],
                'product' => ['properties' => ['id' => ['type' => 'keyword']]],
                'impression' => ['properties' => ['position' => ['type' => 'integer']]],
            ]],
        ]]);

        $docs = [];
        for ($i = 0; $i < 10; ++$i) {
            $docs[] = [$this->impressions, $this->impression('1', 1)];
            $docs[] = [$this->impressions, $this->impression('2', 2)];
        }
        $docs[] = [$this->clicks, $this->click('1', 1)];
        $docs[] = [$this->clicks, $this->click('1', 1)];
        $docs[] = [$this->clicks, $this->click('2', 2)];
        $docs[] = [$this->clicks, $this->click('3', 25)];
        // Poza oknem [as_of − 30 d, as_of): dokładnie na as_of i sekundę przed początkiem.
        $docs[] = [$this->clicks, $this->click('2', 2, self::AS_OF)];
        $docs[] = [$this->impressions, $this->impression('999', 1, '2026-10-20T23:59:59Z')];
        $docs[] = [$this->clicks, $this->click('999', 1, '2026-10-20T23:59:59Z')];
        // Inny typ zdarzenia w tym samym indeksie nie liczy się jako kliknięcie.
        $docs[] = [$this->clicks, ['@timestamp' => self::INSIDE, 'event' => ['type' => 'user.added_to_cart'], 'product' => ['id' => '2']]];

        $body = [];
        foreach ($docs as [$index, $doc]) {
            $body[] = ['index' => ['_index' => $index]];
            $body[] = $doc;
        }
        $response = $this->client->bulk(['body' => $body, 'refresh' => 'true'])->asArray();
        self::assertFalse($response['errors'], json_encode(\array_slice($response['items'], 0, 2)));
    }

    /** @return array<string, mixed> */
    private function impression(string $productId, int $position, string $at = self::INSIDE): array
    {
        return ['@timestamp' => $at, 'product' => ['id' => $productId], 'impression' => ['position' => $position]];
    }

    /** @return array<string, mixed> */
    private function click(string $productId, int $position, string $at = self::INSIDE): array
    {
        return ['@timestamp' => $at, 'event' => ['type' => 'user.clicked_result'], 'product' => ['id' => $productId], 'click' => ['position' => $position]];
    }

    /** @return list<string> product_id w indeksie popularności, rosnąco */
    private function popularityIds(): array
    {
        $this->client->indices()->refresh(['index' => $this->source]);
        $hits = $this->client->search(['index' => $this->source, 'body' => [
            'size' => 100, 'sort' => [['product_id' => 'asc']], '_source' => ['product_id'],
        ]])->asArray()['hits']['hits'];

        return array_map(static fn (array $hit): string => $hit['_source']['product_id'], $hits);
    }

    /** @return array<string, mixed> */
    private function popularityRow(string $productId): array
    {
        $hits = $this->client->search(['index' => $this->source, 'body' => [
            'query' => ['term' => ['product_id' => $productId]],
        ]])->asArray()['hits']['hits'];
        self::assertCount(1, $hits);

        return $hits[0]['_source'];
    }

    /** @return array<string, mixed> `_source` produktu */
    private function productSource(string $id): array
    {
        $this->client->indices()->refresh(['index' => $this->products]);

        return $this->client->get(['index' => $this->products, 'id' => $id])->asArray()['_source'];
    }

    /** @return array<string, mixed> */
    private function analyticsJson(string $file): array
    {
        return json_decode(file_get_contents(self::ANALYTICS_DIR.'/'.$file), associative: true, flags: \JSON_THROW_ON_ERROR);
    }

    private function quietly(\Closure $call): void
    {
        try {
            $call();
        } catch (\Throwable) {
        }
    }
}
