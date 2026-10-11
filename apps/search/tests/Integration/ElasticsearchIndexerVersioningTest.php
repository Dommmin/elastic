<?php

namespace App\Tests\Integration;

use App\Service\ElasticsearchClientFactory;
use App\Service\ElasticsearchIndexer;
use App\Service\IndexOutcome;
use Elastic\Elasticsearch\Client;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * External versioning na PRAWDZIWYM Elasticsearchu — mock klienta nie
 * powie, czy ES faktycznie odrzuci starszą wersję 409 (to robi ES, nie my).
 *
 * Regresja błędu z docs/superpowers/specs/2026-10-11-panel-edycji-design.md
 * (część 1): wersją był `sequence` zdarzenia, czyli lokalny licznik
 * agregatu, więc druga oferta produktu z niskim numerem dostawała 409
 * i zmiana ginęła po cichu. Teraz wersja = czas odczytu projekcji (µs).
 *
 * Test NIE dotyka aliasu products-search (pisze do niego żywy
 * search-consumer). Tworzy jednorazowy indeks z tym samym mapowaniem
 * (`dynamic: strict` — przy okazji sprawdza, że do `_source` nie trafia
 * pole spoza mapowania, np. `projected_at_us`) i usuwa go w tearDown.
 *
 * Wymaga: ES z tej samej konfiguracji co aplikacja (ELASTICSEARCH_* —
 * kontener search-consumer ma je ustawione). Inaczej test jest pomijany,
 * nie zielony.
 */
#[Group('integration')]
final class ElasticsearchIndexerVersioningTest extends TestCase
{
    private const PRODUCT_ID = '7';

    private Client $client;
    private string $index;
    private ElasticsearchIndexer $indexer;

    protected function setUp(): void
    {
        $host = $this->env('ELASTICSEARCH_HOST');
        if ($host === '') {
            self::markTestSkipped('Brak ELASTICSEARCH_HOST — uruchom w kontenerze search-consumer.');
        }

        $this->client = (new ElasticsearchClientFactory(
            $host,
            $this->env('ELASTICSEARCH_USER'),
            $this->env('ELASTICSEARCH_PASSWORD'),
        ))->create();

        try {
            $this->client->info();
        } catch (\Throwable $e) {
            self::markTestSkipped("Elasticsearch nieosiągalny ({$host}): {$e->getMessage()}");
        }

        $this->index = 'products-versioning-test-'.uniqid();
        $this->client->indices()->create(['index' => $this->index, 'body' => $this->mapping()]);
        $this->indexer = new ElasticsearchIndexer($this->client, alias: $this->index);
    }

    protected function tearDown(): void
    {
        if (isset($this->index)) {
            $this->client->indices()->delete(['index' => $this->index, 'ignore_unavailable' => true]);
        }
    }

    public function test_druga_oferta_z_tym_samym_sequence_nie_ginie(): void
    {
        self::assertSame(IndexOutcome::Indexed, $this->indexer->indexProduct($this->projection(priceMin: 87080), 1_000));
        self::assertSame(IndexOutcome::Indexed, $this->indexer->indexProduct($this->projection(priceMin: 1999), 2_000));

        self::assertSame(1999, $this->document()['_source']['price_min']);
        self::assertSame(2_000, $this->document()['_version']);
    }

    public function test_starszy_odczyt_przegrywa_i_jest_sukcesem(): void
    {
        $this->indexer->indexProduct($this->projection(priceMin: 1999), 2_000);

        self::assertSame(IndexOutcome::Stale, $this->indexer->indexProduct($this->projection(priceMin: 87080), 1_500));
        self::assertSame(1999, $this->document()['_source']['price_min']);
    }

    public function test_delete_starszy_niz_dokument_jest_stale(): void
    {
        $this->indexer->indexProduct($this->projection(priceMin: 1999), 2_000);

        self::assertSame(IndexOutcome::Stale, $this->indexer->deleteProduct(self::PRODUCT_ID, 1_000));
        self::assertTrue($this->client->exists(['index' => $this->index, 'id' => self::PRODUCT_ID])->asBool());
    }

    /** @return array<string, mixed> */
    private function projection(int $priceMin): array
    {
        return [
            'id' => (int) self::PRODUCT_ID,
            'projected_at_us' => 1790000000000000,
            'version' => 1,
            'name' => 'Produkt testowy wersjonowania',
            'description' => null,
            'brand' => null,
            'category' => null,
            'attributes' => [],
            'price_min' => $priceMin,
            'price_max' => 87080,
            'in_stock' => true,
            'rating_avg' => null,
            'rating_count' => 0,
            'offers' => [],
            'created_at' => '2026-10-11T12:00:00+00:00',
            'indexed_at' => '2026-10-11T12:00:00+00:00',
        ];
    }

    /** @return array<string, mixed> */
    private function document(): array
    {
        return $this->client->get(['index' => $this->index, 'id' => self::PRODUCT_ID])->asArray();
    }

    /**
     * To samo mapowanie, co products-v1 (CreateSearchIndexCommand): w kontenerze
     * montowane pod /infra, na hoście leży w repo.
     *
     * @return array<string, mixed>
     */
    private function mapping(): array
    {
        $candidates = [
            '/infra/elasticsearch/mappings/products-v1.json',
            \dirname(__DIR__, 4).'/infra/elasticsearch/mappings/products-v1.json',
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                $body = json_decode(file_get_contents($path), associative: true, flags: \JSON_THROW_ON_ERROR);
                unset($body['_meta']);

                return $body;
            }
        }

        self::markTestSkipped('Brak pliku mapowania products-v1.json.');
    }

    private function env(string $name): string
    {
        return (string) ($_SERVER[$name] ?? getenv($name) ?: '');
    }
}
