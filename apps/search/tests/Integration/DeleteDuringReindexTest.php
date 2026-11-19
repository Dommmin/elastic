<?php

namespace App\Tests\Integration;

use App\Service\CatalogProjectionClient;
use App\Service\ElasticsearchClientFactory;
use App\Service\ElasticsearchIndexer;
use App\Service\IndexAliases;
use App\Service\IndexOutcome;
use App\Service\ProductSynchronizer;
use App\Tracing\TraceContext;
use Elastic\Elasticsearch\Client;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Wyścig usunięć podczas reindeksu (spec ETAP 10, 5.4): produkt usunięty PO
 * przepięciu `products-write` na nowy indeks, ale PRZED skopiowaniem go przez
 * `_reindex`, nie może wrócić z starego indeksu.
 *
 * Wszystko na jednorazowych indeksach i aliasach `products-*-<uniqid>`
 * (rola search_service obejmuje tylko `products-*`; tearDown kasuje nazwy
 * jawnie — ES odrzuca wildcardowe usunięcia).
 */
#[Group('integration')]
final class DeleteDuringReindexTest extends TestCase
{
    private const ID = '42';
    private const VERSION = 5_000_000;

    private Client $client;
    private string $old;
    private string $new;
    private string $writeAlias;
    private string $readAlias;
    private IndexAliases $aliases;

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

        $suffix = uniqid();
        $this->old = "products-race-old-{$suffix}";
        $this->new = "products-race-new-{$suffix}";
        $this->writeAlias = "products-race-write-{$suffix}";
        $this->readAlias = "products-race-search-{$suffix}";
        $this->aliases = new IndexAliases($this->client, $this->readAlias, $this->writeAlias);
    }

    protected function tearDown(): void
    {
        if (! isset($this->client)) {
            return;
        }
        foreach ([$this->old, $this->new] as $index) {
            $this->client->indices()->delete(['index' => $index, 'ignore_unavailable' => true]);
        }
    }

    public function test_usuniecie_dokumentu_obecnego_tylko_w_starym_indeksie_nie_wskrzesza_go_po_reindeksie(): void
    {
        $this->createIndex($this->old);
        $this->createIndex($this->new, gcDeletes: '1h');

        // Stan przed migracją: dokument żyje w starym indeksie.
        (new ElasticsearchIndexer($this->client, $this->old))->indexProduct($this->projection(), self::VERSION);

        // Krok 3 migracji: zapis przepięty na nowy indeks (który dokumentu jeszcze nie ma).
        $this->aliases->attach($this->writeAlias, $this->new);

        // product.deleted w trakcie migracji — idzie przez alias zapisu, więc do nowego indeksu.
        $writer = new ElasticsearchIndexer($this->client, $this->writeAlias);
        self::assertSame(IndexOutcome::NotFound, $writer->deleteProduct(self::ID, self::VERSION + 1_000));

        $this->reindex();

        self::assertFalse(
            $this->client->exists(['index' => $this->new, 'id' => self::ID])->asBool(),
            'Z gc_deletes=1h tombstone usunięcia powinien zablokować kopię ze starego indeksu.',
        );
    }

    /**
     * DOKUMENTACJA domyślnego zachowania, nie wymaganie. Z `gc_deletes: 0s`
     * tombstone znika od razu po następnym odświeżeniu/GC, więc starsza wersja
     * ze `_reindex` przechodzi. Asercja opisuje to, co ES faktycznie robi.
     */
    public function test_z_krotkim_gc_deletes_dokument_wraca(): void
    {
        $this->createIndex($this->old);
        $this->createIndex($this->new, gcDeletes: '0s');

        (new ElasticsearchIndexer($this->client, $this->old))->indexProduct($this->projection(), self::VERSION);
        $this->aliases->attach($this->writeAlias, $this->new);

        $writer = new ElasticsearchIndexer($this->client, $this->writeAlias);
        self::assertSame(IndexOutcome::NotFound, $writer->deleteProduct(self::ID, self::VERSION + 1_000));

        // gc_deletes liczy się od czasu usunięcia; _refresh + chwila dają GC
        // tombstone'a szansę zadziałać.
        $this->client->indices()->refresh(['index' => $this->new]);
        sleep(2);
        $this->client->indices()->refresh(['index' => $this->new]);

        $this->reindex();

        $resurrected = $this->client->exists(['index' => $this->new, 'id' => self::ID])->asBool();
        self::assertTrue($resurrected, 'Przy gc_deletes=0s ES nie pamięta usunięcia i dokument wraca (zombie).');
    }

    public function test_wersja_usuniecia_jest_wieksza_niz_wersja_ostatniej_projekcji(): void
    {
        $this->createIndex($this->old);
        $this->aliases->attach($this->writeAlias, $this->old);

        $catalog = new CatalogProjectionClient('http://catalog.invalid', new TraceContext, new MockHttpClient([
            new MockResponse(json_encode($this->projection() + ['projected_at_us' => 1_790_000_000_000_000]), ['http_code' => 200]),
            new MockResponse(json_encode(['projected_at_us' => 1_790_000_000_500_000]), ['http_code' => 404]),
        ]));
        $sync = new ProductSynchronizer($catalog, new ElasticsearchIndexer($this->client, $this->writeAlias));

        $first = $sync->sync(self::ID);
        $second = $sync->sync(self::ID);

        self::assertSame(IndexOutcome::Indexed, $first->outcome);
        self::assertFalse($first->deleted);
        self::assertTrue($second->deleted);
        self::assertGreaterThan($first->docVersion, $second->docVersion);
        self::assertSame(IndexOutcome::Indexed, $second->outcome);
        self::assertFalse($this->client->exists(['index' => $this->old, 'id' => self::ID])->asBool());
    }

    private function createIndex(string $index, ?string $gcDeletes = null): void
    {
        $body = $this->mapping();
        if ($gcDeletes !== null) {
            $body['settings']['index']['gc_deletes'] = $gcDeletes;
        }
        $this->client->indices()->create(['index' => $index, 'body' => $body]);
    }

    private function reindex(): void
    {
        $this->client->reindex([
            'refresh' => true,
            'body' => [
                'conflicts' => 'proceed',
                'source' => ['index' => $this->old],
                'dest' => ['index' => $this->new, 'version_type' => 'external'],
            ],
        ]);
        $this->client->indices()->refresh(['index' => $this->new]);
    }

    /** @return array<string, mixed> */
    private function projection(): array
    {
        return [
            'id' => (int) self::ID,
            'projected_at_us' => self::VERSION,
            'version' => 1,
            'name' => 'Produkt wyścigu usunięć',
            'description' => null,
            'brand' => null,
            'category' => null,
            'attributes' => [],
            'price_min' => 1999,
            'price_max' => 1999,
            'in_stock' => true,
            'rating_avg' => null,
            'rating_count' => 0,
            'offers' => [],
            'created_at' => '2026-11-19T12:00:00+00:00',
            'indexed_at' => '2026-11-19T12:00:00+00:00',
        ];
    }

    /** @return array<string, mixed> */
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
