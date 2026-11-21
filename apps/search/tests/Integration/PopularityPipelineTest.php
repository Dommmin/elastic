<?php

namespace App\Tests\Integration;

use App\Service\ElasticsearchIndexer;
use App\Service\IndexOutcome;
use App\Tests\Support\RealElasticsearch;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Pipeline `products-enrich-popularity` (ETAP 12, spec §7) na PRAWDZIWYM
 * Elasticsearchu — mock nie powie, czy enrich widzi `_id`, czy `on_failure`
 * naprawdę przepuszcza dokument i czy `rank_feature` przyjmie wartość.
 *
 * Wszystko z plików repo (pipeline, polityka, indeks popularności, mapowanie
 * products-v6) pod jednorazowymi nazwami `*-test-<uniqid>`: nazwa polityki
 * podmieniona w pipeline, nazwa pipeline'u w `index.default_pipeline`.
 * Żywych aliasów, polityki `product-popularity-policy` ani pipeline'u
 * produkcyjnego test nie dotyka. Działa jako `searchsvc` (rola search_service:
 * manage_enrich, manage_pipeline, product-popularity*, products-*), więc przy
 * okazji sprawdza uprawnienia, z którymi Task 9 będzie odświeżał popularność.
 */
#[Group('integration')]
final class PopularityPipelineTest extends TestCase
{
    use RealElasticsearch;

    private const ANALYTICS_DIR = '/infra/elasticsearch/analytics';
    private const VERSION = 1_790_000_000_000_000;

    private Client $client;
    private string $suffix;
    private string $source;
    private string $policy;
    private string $pipeline;
    private string $index;

    protected function setUp(): void
    {
        $this->client = $this->elasticsearchOrSkip();
        if (! is_dir(self::ANALYTICS_DIR)) {
            self::markTestSkipped('Brak katalogu '.self::ANALYTICS_DIR.' (montowany w kontenerze search-consumer).');
        }

        $this->suffix = uniqid();
        $this->source = "product-popularity-test-{$this->suffix}";
        $this->policy = "product-popularity-test-{$this->suffix}";
        $this->pipeline = "products-enrich-popularity-test-{$this->suffix}";
        $this->index = "products-poptest-{$this->suffix}";
    }

    protected function tearDown(): void
    {
        if (! isset($this->suffix)) {
            return;
        }
        // Kolejność wymuszona przez ES: indeks z default_pipeline → pipeline → polityka
        // (ES odmawia usunięcia polityki, do której odwołuje się pipeline) → źródło.
        $this->client->indices()->delete(['index' => $this->index, 'ignore_unavailable' => true]);
        $this->quietly(fn () => $this->client->ingest()->deletePipeline(['id' => $this->pipeline]));
        $this->quietly(fn () => $this->client->enrich()->deletePolicy(['name' => $this->policy]));
        $this->client->indices()->delete(['index' => $this->source, 'ignore_unavailable' => true]);
    }

    public function test_produkt_z_wpisem_dostaje_popularnosc_ze_strumienia(): void
    {
        $this->setUpEnrich(['10' => ['popularity' => 0.31, 'popularity_raw' => 0.22, 'popularity_ips' => 0.31]]);

        self::assertSame(IndexOutcome::Indexed, $this->indexer()->indexProduct($this->projection(10), self::VERSION));

        $doc = $this->document('10');
        self::assertEqualsWithDelta(0.31, $doc['_source']['popularity'], 1e-9);
        self::assertEqualsWithDelta(0.22, $doc['_source']['popularity_raw'], 1e-9);
        self::assertEqualsWithDelta(0.31, $doc['_source']['popularity_ips'], 1e-9);
        self::assertArrayNotHasKey('_pop', $doc['_source']);
        self::assertSame(self::VERSION, $doc['_version'], 'pipeline nie rusza wersji zewnętrznej');
        self::assertSame(1, $this->countRankFeature(), 'rank_feature zaindeksowany — da się po nim punktować');
    }

    public function test_bulk_sync_tez_przechodzi_przez_pipeline(): void
    {
        $this->setUpEnrich(['20' => ['popularity' => 0.5, 'popularity_raw' => 0.4, 'popularity_ips' => 0.5]]);

        $result = $this->indexer()->bulkSync([$this->projection(20), $this->projection(21)], [], self::VERSION);

        self::assertSame(2, $result['indexed'], json_encode($result['error_samples']));
        self::assertEqualsWithDelta(0.5, $this->document('20')['_source']['popularity'], 1e-9);
        self::assertArrayNotHasKey('popularity', $this->document('21')['_source']);
    }

    public function test_produkt_bez_wpisu_zapisany_bez_pol_popularnosci(): void
    {
        $this->setUpEnrich(['10' => ['popularity' => 0.31, 'popularity_raw' => 0.22, 'popularity_ips' => 0.31]]);

        self::assertSame(IndexOutcome::Indexed, $this->indexer()->indexProduct($this->projection(11), self::VERSION));

        $source = $this->document('11')['_source'];
        self::assertSame('Produkt 11', $source['name']);
        foreach (['popularity', 'popularity_raw', 'popularity_ips', '_pop'] as $field) {
            self::assertArrayNotHasKey($field, $source);
        }
    }

    public function test_stara_popularnosc_z_source_nie_przezywa_bez_wpisu(): void
    {
        // _reindex vN → vN+1 i _update_by_query niosą pola popularności w _source.
        // Źródłem prawdy jest indeks enrich: produkt, który z niego wypadł, traci pola.
        $this->setUpEnrich([]);

        $this->client->index(['index' => $this->index, 'id' => '12', 'refresh' => 'true',
            'body' => $this->product(12) + ['popularity' => 0.9, 'popularity_raw' => 0.8, 'popularity_ips' => 0.9]]);

        foreach (['popularity', 'popularity_raw', 'popularity_ips'] as $field) {
            self::assertArrayNotHasKey($field, $this->document('12')['_source']);
        }
    }

    public function test_zero_w_zrodle_pomija_rank_feature_a_zapis_przechodzi(): void
    {
        // rank_feature przyjmuje tylko wartości > 0 — bez warunku 0 odrzuciłby cały dokument.
        $this->setUpEnrich(['13' => ['popularity' => 0.0, 'popularity_raw' => 0.0, 'popularity_ips' => 0.0]]);

        self::assertSame(IndexOutcome::Indexed, $this->indexer()->indexProduct($this->projection(13), self::VERSION));

        $source = $this->document('13')['_source'];
        self::assertArrayNotHasKey('popularity', $source);
        self::assertSame(0, (int) $source['popularity_raw']);
        self::assertSame(0, (int) $source['popularity_ips']);
    }

    public function test_awaria_w_pipeline_nie_odrzuca_zapisu(): void
    {
        // Uszkodzony wpis (napis zamiast liczby, indeks źródłowy bez jawnego mapowania):
        // porównanie `> 0` w warunku rzuca wyjątek w trakcie przetwarzania — ten sam
        // tor co „brak indeksu enrich” w procesorze. on_failure kasuje pola i przepuszcza dokument.
        $this->setUpEnrich(['14' => ['popularity' => 'zepsute', 'popularity_raw' => 0.1, 'popularity_ips' => 0.1]], explicitMapping: false);

        self::assertSame(IndexOutcome::Indexed, $this->indexer()->indexProduct($this->projection(14), self::VERSION));

        $source = $this->document('14')['_source'];
        self::assertSame('Produkt 14', $source['name']);
        foreach (['popularity', 'popularity_raw', 'popularity_ips', '_pop'] as $field) {
            self::assertArrayNotHasKey($field, $source);
        }
    }

    public function test_polityki_w_uzyciu_nie_da_sie_usunac_a_zapisy_ida_dalej(): void
    {
        $this->setUpEnrich(['15' => ['popularity' => 0.2, 'popularity_raw' => 0.2, 'popularity_ips' => 0.2]]);

        try {
            $this->client->enrich()->deletePolicy(['name' => $this->policy]);
            self::fail('ES usunął politykę, do której odwołuje się pipeline — stan „brak polityki” jest możliwy.');
        } catch (ClientResponseException $e) {
            self::assertSame(409, $e->getCode());
            self::assertStringContainsString('pipeline is referencing it', (string) $e->getResponse()->getBody());
        }

        self::assertSame(IndexOutcome::Indexed, $this->indexer()->indexProduct($this->projection(15), self::VERSION));
        self::assertEqualsWithDelta(0.2, $this->document('15')['_source']['popularity'], 1e-9);
    }

    public function test_pipeline_nie_powstanie_bez_wykonanej_polityki(): void
    {
        // Dlatego setup tworzy pusty product-popularity i od razu wykonuje politykę:
        // bez indeksu enrich nie ma pipeline'u, a bez pipeline'u products-v6 odrzuca zapisy.
        $this->createSource([], explicitMapping: true);
        $this->client->enrich()->putPolicy(['name' => $this->policy, 'body' => $this->policyBody()]);

        try {
            $this->client->ingest()->putPipeline(['id' => $this->pipeline, 'body' => $this->pipelineBody()]);
            self::fail('Pipeline powstał bez indeksu enrich.');
        } catch (\Throwable $e) {
            self::assertStringContainsString('no enrich index exists', $e->getMessage().(method_exists($e, 'getResponse') ? (string) $e->getResponse()->getBody() : ''));
        }
    }

    public function test_update_by_query_z_pipeline_dokleja_nowa_popularnosc(): void
    {
        // Ścieżka odświeżenia z Task 9: nowy wpis → _execute → _update_by_query?pipeline=.
        $this->setUpEnrich([]);
        $this->indexer()->indexProduct($this->projection(16), self::VERSION);
        self::assertArrayNotHasKey('popularity', $this->document('16')['_source']);

        $this->client->index(['index' => $this->source, 'id' => '16', 'refresh' => 'true',
            'body' => ['product_id' => '16', 'popularity' => 0.44, 'popularity_raw' => 0.4, 'popularity_ips' => 0.44, 'impressions' => 10, 'clicks' => 4]]);
        $this->client->enrich()->executePolicy(['name' => $this->policy, 'wait_for_completion' => true]);

        $response = $this->client->updateByQuery([
            'index' => $this->index, 'pipeline' => $this->pipeline, 'conflicts' => 'proceed', 'refresh' => true,
        ])->asArray();

        self::assertSame([], $response['failures']);
        $doc = $this->document('16');
        self::assertEqualsWithDelta(0.44, $doc['_source']['popularity'], 1e-9);
        self::assertSame(self::VERSION + 1, $doc['_version'], 'UBQ podbija wersję o 1 — strumień z projected_at_us dalej wygrywa');
    }

    // --- klocki -------------------------------------------------------------

    /**
     * Źródło popularności z wpisami → polityka (wykonana) → pipeline → indeks products-v6.
     *
     * @param array<string, array<string, mixed>> $rows product_id => pola popularności
     */
    private function setUpEnrich(array $rows, bool $explicitMapping = true): void
    {
        $this->createSource($rows, $explicitMapping);
        $this->client->enrich()->putPolicy(['name' => $this->policy, 'body' => $this->policyBody()]);
        $this->client->enrich()->executePolicy(['name' => $this->policy, 'wait_for_completion' => true]);
        $this->client->ingest()->putPipeline(['id' => $this->pipeline, 'body' => $this->pipelineBody()]);

        $body = $this->mappingBody(6);
        self::assertSame('products-enrich-popularity', $body['settings']['index']['default_pipeline']);
        $body['settings']['index']['default_pipeline'] = $this->pipeline;
        $body['settings']['number_of_replicas'] = 0;
        $this->client->indices()->create(['index' => $this->index, 'body' => $body]);
    }

    /** @param array<string, array<string, mixed>> $rows */
    private function createSource(array $rows, bool $explicitMapping): void
    {
        $body = ['settings' => ['number_of_replicas' => 0]];
        if ($explicitMapping) {
            $body = $this->analyticsJson('index-product-popularity.json');
            $body['settings']['number_of_replicas'] = 0;
        }
        $this->client->indices()->create(['index' => $this->source, 'body' => $body]);

        foreach ($rows as $productId => $fields) {
            $this->client->index(['index' => $this->source, 'id' => (string) $productId,
                'body' => ['product_id' => (string) $productId, 'impressions' => 100, 'clicks' => 10] + $fields]);
        }
        $this->client->indices()->refresh(['index' => $this->source]);
    }

    /** @return array<string, mixed> polityka z repo, wskazująca jednorazowe źródło */
    private function policyBody(): array
    {
        $policy = $this->analyticsJson('enrich-policy-product-popularity.json');
        self::assertSame('product-popularity', $policy['match']['indices']);
        $policy['match']['indices'] = $this->source;

        return $policy;
    }

    /** @return array<string, mixed> pipeline z repo, z nazwą polityki testowej */
    private function pipelineBody(): array
    {
        $pipeline = $this->analyticsJson('pipeline-products-enrich-popularity.json');
        $replaced = 0;
        foreach ($pipeline['processors'] as &$processor) {
            if (isset($processor['enrich'])) {
                self::assertSame('product-popularity-policy', $processor['enrich']['policy_name']);
                $processor['enrich']['policy_name'] = $this->policy;
                ++$replaced;
            }
        }
        unset($processor);
        self::assertSame(1, $replaced, 'pipeline ma dokładnie jeden procesor enrich');

        return $pipeline;
    }

    /** @return array<string, mixed> */
    private function analyticsJson(string $file): array
    {
        return json_decode(file_get_contents(self::ANALYTICS_DIR.'/'.$file), associative: true, flags: \JSON_THROW_ON_ERROR);
    }

    private function indexer(): ElasticsearchIndexer
    {
        return new ElasticsearchIndexer($this->client, alias: $this->index);
    }

    /** @return array<string, mixed> projekcja catalogu (id + projected_at_us + version nie trafiają do _source) */
    private function projection(int $id): array
    {
        return ['id' => $id, 'projected_at_us' => self::VERSION, 'version' => 1] + $this->product($id);
    }

    /** @return array<string, mixed> */
    private function document(string $id): array
    {
        $this->client->indices()->refresh(['index' => $this->index]);

        return $this->client->get(['index' => $this->index, 'id' => $id])->asArray();
    }

    private function countRankFeature(): int
    {
        $this->client->indices()->refresh(['index' => $this->index]);

        return (int) $this->client->count(['index' => $this->index, 'body' => [
            'query' => ['rank_feature' => ['field' => 'popularity']],
        ]])->asArray()['count'];
    }

    private function quietly(\Closure $call): void
    {
        try {
            $call();
        } catch (\Throwable) {
        }
    }
}
