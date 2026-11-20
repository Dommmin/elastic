<?php

namespace App\Tests\Integration\Command;

use App\Command\IndexBackfillCommand;
use App\Command\IndexMigrateCommand;
use App\Service\CatalogProjectionClient;
use App\Service\ElasticsearchIndexer;
use App\Service\IndexAliases;
use App\Service\IndexGate;
use App\Service\IndexMapping;
use App\Service\IndexMigration;
use App\Tests\Support\RealElasticsearch;
use App\Tracing\TraceContext;
use Elastic\Elasticsearch\Client;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * `search:index:backfill` (droga B, spec 5.6) na prawdziwym ES: indeksy
 * `products-bf-<uniq>-vN`, aliasy `products-bf-read|write-<uniq>`; catalog
 * to MockHttpClient z listą ID i projekcjami. Żywych aliasów test nie dotyka.
 */
#[Group('integration')]
final class IndexBackfillCommandTest extends TestCase
{
    use RealElasticsearch;

    private const PROJECTED_AT = 1_790_000_000_000_000;

    private Client $client;
    private string $prefix;
    private string $read;
    private string $write;
    private string $mappingsDir;
    private IndexAliases $aliases;
    /** @var list<int> ID istniejące w "catalogu" */
    private array $catalogIds = [];
    /** @var list<int> ID, których projekcja kończy się 500 (błąd przejściowy) */
    private array $failingIds = [];
    /** @var list<int>|null ID z działającą projekcją (null = wszystkie z katalogu) */
    private ?array $catalogProjectable = null;
    /** @var list<int> ID zwracane przez /products/changed */
    private array $changedIds = [];
    /** @var list<string> ścieżki z zapytaniem, o które pytał backfill */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->client = $this->elasticsearchOrSkip();

        $id = uniqid();
        $this->prefix = "products-bf-{$id}-v";
        $this->read = "products-bf-read-{$id}";
        $this->write = "products-bf-write-{$id}";
        $this->aliases = new IndexAliases($this->client, $this->read, $this->write);

        $this->mappingsDir = sys_get_temp_dir()."/bf-mappings-{$id}";
        mkdir($this->mappingsDir);
        copy(self::MAPPINGS_DIR.'/products-v1.json', $this->mappingsDir.'/products-v1.json');
        copy(self::MAPPINGS_DIR.'/products-v2.json', $this->mappingsDir.'/products-v2.json');

        $this->client->indices()->create(['index' => $this->prefix.'1', 'body' => $this->mappingBody(1)]);
        $this->aliases->attach($this->read, $this->prefix.'1');
        $this->aliases->attach($this->write, $this->prefix.'1');
    }

    protected function tearDown(): void
    {
        if (! isset($this->prefix)) {
            return;
        }
        foreach ([$this->prefix.'1', $this->prefix.'2'] as $index) {
            $this->client->indices()->delete(['index' => $index, 'ignore_unavailable' => true]);
        }
        foreach (glob($this->mappingsDir.'/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->mappingsDir);
    }

    public function test_trzy_strony_id_trafiaja_do_indeksu_z_wersja_projected_at_us(): void
    {
        $this->prepareTarget();
        $this->catalogIds = range(1, 7);

        $tester = $this->backfill($this->prefix.'2', ['--batch' => '3']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('indexed: 7, stale(409): 0, not_found: 0', $tester->getDisplay());
        self::assertSame(3, substr_count($tester->getDisplay(), 'strona '), 'Trzy strony: 3 + 3 + 1.');
        $this->client->indices()->refresh(['index' => $this->prefix.'2']);
        self::assertSame(7, $this->countDocs($this->client, $this->prefix.'2'));
        // Jedna wersja na partię: projected_at_us odpowiedzi (fałszywy catalog: PROJECTED_AT + największe ID partii).
        foreach ($this->catalogIds as $id) {
            self::assertSame(self::PROJECTED_AT + min(7, (int) ceil($id / 3) * 3), $this->client->get(['index' => $this->prefix.'2', 'id' => (string) $id])->asArray()['_version']);
        }
    }

    public function test_pelny_backfill_pobiera_projekcje_partiami_nie_po_jednej(): void
    {
        $this->prepareTarget();
        $this->catalogIds = range(1, 7);

        $this->assertBackfill($this->prefix.'2', ['--batch' => '3']);

        $projections = array_values(array_filter($this->requests, static fn (string $r) => str_contains($r, '/projection')));
        self::assertSame([
            '/api/internal/products/projections?ids=1,2,3',
            '/api/internal/products/projections?ids=4,5,6',
            '/api/internal/products/projections?ids=7',
        ], array_map('urldecode', $projections));
    }

    public function test_since_bierze_id_z_changed_i_nie_rusza_pieczeci(): void
    {
        $this->prepareTarget();
        $this->catalogIds = range(1, 3);
        $this->assertBackfill($this->prefix.'2');
        $sealed = $this->meta();
        self::assertSame('backfill', $sealed['fill_method']);
        (new ElasticsearchIndexer($this->client, $this->prefix.'2'))->indexProduct(['id' => 2] + $this->product(2, 'Ze strumienia'), self::PROJECTED_AT + 1_000);

        // 2 zmieniony (strumień już zapisał nowszą wersję), 9 usunięty w catalogu i nigdy nie zaindeksowany.
        $this->changedIds = [2, 9];
        $this->requests = [];
        $tester = $this->backfill($this->prefix.'2', ['--since' => '2026-11-20T00:35:06+00:00']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('stale(409): 1, not_found: 1, deleted: 0, errors: 0', $tester->getDisplay());
        self::assertSame([
            '/api/internal/products/changed?since=2026-11-20T00:35:06+00:00&limit=500',
            '/api/internal/products/projections?ids=2,9',
        ], array_map('urldecode', $this->requests));
        self::assertSame($sealed, $this->meta(), '--since to nadrabianie: pieczęć ani nie kasowana, ani nie stawiana.');
        self::assertStringContainsString('nadrabianie', $this->flat($tester));
    }

    public function test_since_na_celu_bez_pieczeci_jej_nie_stawia(): void
    {
        $this->prepareTarget();
        $this->changedIds = [1];
        $this->catalogIds = [1];

        $tester = $this->backfill($this->prefix.'2', ['--since' => '2026-11-20T00:00:00+00:00']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('indexed: 1', $tester->getDisplay());
        self::assertArrayNotHasKey('fill_method', $this->meta());
        self::assertArrayNotHasKey('backfill_completed_at', $this->meta());
    }

    public function test_since_z_bledem_konczy_sie_kodem_bledu(): void
    {
        $this->prepareTarget();
        $this->changedIds = [1, 2];
        $this->catalogIds = [1, 2];
        $this->failingIds = [2];

        $tester = $this->backfill($this->prefix.'2', ['--since' => '2026-11-20T00:00:00+00:00']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('errors: 2', $tester->getDisplay());
    }

    public function test_since_z_bledna_data_odmawia(): void
    {
        $tester = $this->backfill($this->write, ['--since' => 'wczoraj']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame([], $this->requests);
    }

    /** Przesunięcie strefy w --since nie może przesunąć okna: do catalogu idzie ta sama chwila w UTC. */
    public function test_since_ze_strefa_inna_niz_utc_idzie_do_catalogu_w_utc(): void
    {
        $this->changedIds = [];

        $tester = $this->backfill($this->write, ['--since' => '2026-11-20T01:35:06+01:00']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(['/api/internal/products/changed?since=2026-11-20T00:35:06+00:00&limit=500'], array_map('urldecode', $this->requests));
    }

    public function test_batch_ponad_500_odmawia(): void
    {
        $tester = $this->backfill($this->write, ['--batch' => '501']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('1..500', $this->flat($tester));
    }

    public function test_dokument_z_wyzsza_wersja_jest_stale_i_nie_zostaje_nadpisany(): void
    {
        $this->prepareTarget();
        $this->catalogIds = [1, 2];
        (new ElasticsearchIndexer($this->client, $this->prefix.'2'))->indexProduct(
            ['id' => 2] + $this->product(2, 'Ze strumienia'),
            self::PROJECTED_AT + 1_000,
        );

        $tester = $this->backfill($this->prefix.'2');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('indexed: 1, stale(409): 1', $tester->getDisplay());
        $doc = $this->client->get(['index' => $this->prefix.'2', 'id' => '2'])->asArray();
        self::assertSame('Ze strumienia', $doc['_source']['name']);
        self::assertSame(self::PROJECTED_AT + 1_000, $doc['_version']);
    }

    public function test_404_projekcji_to_not_found_i_brak_dokumentu(): void
    {
        $this->prepareTarget();
        $this->catalogIds = [1, 2];
        $this->catalogProjectable = [1];

        $tester = $this->backfill($this->prefix.'2');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('not_found: 1', $tester->getDisplay());
        self::assertFalse($this->client->exists(['index' => $this->prefix.'2', 'id' => '2'])->asBool());
    }

    public function test_backfill_do_biezacego_celu_odczytu_dziala_nie_pieczetuje_i_nie_nadpisuje_nowszych(): void
    {
        $this->catalogIds = [1, 2];
        (new ElasticsearchIndexer($this->client, $this->prefix.'1'))->indexProduct(
            ['id' => 2] + $this->product(2, 'Nowsza'),
            self::PROJECTED_AT + 5_000,
        );

        // Przed switch-write oba aliasy (także odczytu) wskazują v1 — scenariusz po reindex-abort.
        $tester = $this->backfill($this->write);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('indexed: 1, stale(409): 1', $tester->getDisplay());
        self::assertSame($this->prefix.'1', $this->aliases->readTarget());
        $this->client->indices()->refresh(['index' => $this->prefix.'1']);
        self::assertSame(2, $this->countDocs($this->client, $this->prefix.'1'));
        self::assertSame('Nowsza', $this->client->get(['index' => $this->prefix.'1', 'id' => '2'])->asArray()['_source']['name']);
        self::assertSame([], $this->meta($this->prefix.'1'), 'Indeks bez _meta.migration nie dostaje pieczęci.');
        self::assertStringContainsString('nie jest celem migracji', $this->flat($tester));
    }

    public function test_dziala_na_aliasie_zapisu_po_switch_write(): void
    {
        $this->prepareTarget();
        $this->catalogIds = [1, 2, 3];

        $tester = $this->backfill($this->write);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $this->client->indices()->refresh(['index' => $this->prefix.'2']);
        self::assertSame(3, $this->countDocs($this->client, $this->prefix.'2'));
    }

    public function test_finalize_i_switch_read_przechodza_po_udanym_backfillu(): void
    {
        $this->prepareTarget();
        $this->catalogIds = range(1, 5);
        $this->assertBackfill($this->prefix.'2');
        $meta = $this->meta();
        self::assertSame('backfill', $meta['fill_method']);
        self::assertSame(5, $meta['backfill_indexed']);
        self::assertSame(0, $meta['backfill_errors']);
        self::assertArrayHasKey('backfill_completed_at', $meta);
        self::assertArrayNotHasKey('reindex_task', $meta);

        $this->assertMigrate('finalize');
        $switch = $this->assertMigrate('switch-read');

        self::assertStringContainsString('GATE=PASS', $switch->getDisplay());
        self::assertSame($this->prefix.'2', $this->aliases->readTarget());
    }

    public function test_finalize_i_switch_read_odmawiaja_po_backfillu_z_bledami(): void
    {
        $this->prepareTarget();
        $this->catalogIds = range(1, 5);
        $this->failingIds = [3];

        // Partia [3, 4] kończy się 500 — cała strona to błędy, reszta idzie dalej.
        $tester = $this->backfill($this->prefix.'2', ['--batch' => '2']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('indexed: 3, stale(409): 0, not_found: 0, deleted: 0, errors: 2', $tester->getDisplay());
        self::assertArrayNotHasKey('fill_method', $this->meta());
        self::assertArrayNotHasKey('backfill_completed_at', $this->meta());

        $finalize = $this->migrate('finalize');
        self::assertSame(Command::FAILURE, $finalize->getStatusCode());
        $this->client->indices()->putSettings(['index' => $this->prefix.'2', 'body' => ['index' => ['number_of_replicas' => 1, 'refresh_interval' => '1s']]]);
        $switch = $this->migrate('switch-read');
        self::assertSame(Command::FAILURE, $switch->getStatusCode());
        self::assertSame($this->prefix.'1', $this->aliases->readTarget());
    }

    public function test_finalize_odmawia_gdy_pieczec_backfillu_bez_daty_ukonczenia(): void
    {
        $this->prepareTarget();
        $this->putMigrationMeta(['fill_method' => 'backfill', 'backfill_errors' => 0]);

        $finalize = $this->migrate('finalize');

        self::assertSame(Command::FAILURE, $finalize->getStatusCode());
        self::assertStringContainsString('Backfill do', $this->flat($finalize));
        self::assertStringContainsString('nie zakończył się pomyślnie', $this->flat($finalize));
    }

    public function test_finalize_odmawia_gdy_pieczec_backfillu_ma_bledy(): void
    {
        $this->prepareTarget();
        $this->putMigrationMeta(['fill_method' => 'backfill', 'backfill_completed_at' => '2026-11-19T12:00:00+00:00', 'backfill_errors' => 1]);

        $finalize = $this->migrate('finalize');

        self::assertSame(Command::FAILURE, $finalize->getStatusCode());
        self::assertStringContainsString('nie zakończył się pomyślnie', $this->flat($finalize));
    }

    /** @param array<string, mixed> $fields */
    private function putMigrationMeta(array $fields): void
    {
        $index = $this->prefix.'2';
        $meta = $this->client->indices()->getMapping(['index' => $index])->asArray()[$index]['mappings']['_meta'];
        $meta['migration'] = $fields + $meta['migration'];
        $this->client->indices()->putMapping(['index' => $index, 'body' => ['_meta' => $meta]]);
    }

    public function test_bledny_przebieg_kasuje_pieczec_poprzedniego_udanego(): void
    {
        $this->prepareTarget();
        $this->catalogIds = range(1, 3);
        $this->assertBackfill($this->prefix.'2');
        self::assertSame('backfill', $this->meta()['fill_method']);

        $this->failingIds = [2];
        $this->backfill($this->prefix.'2');

        self::assertArrayNotHasKey('backfill_completed_at', $this->meta());
        self::assertSame(Command::FAILURE, $this->migrate('finalize')->getStatusCode());
    }

    /**
     * Po udanej migracji v2 jest żywym indeksem (oba aliasy), ale ma `_meta.migration`.
     * Pełny backfill do `products-write` w spoczynku nie może ruszać jej pieczęci —
     * pieczętujemy tylko cel migracji w toku (alias zapisu ≠ alias odczytu).
     */
    public function test_pelny_backfill_do_zywego_indeksu_po_migracji_nie_rusza_pieczeci(): void
    {
        $this->prepareTarget();
        $this->catalogIds = range(1, 3);
        $this->assertBackfill($this->prefix.'2');
        $this->assertMigrate('finalize');
        $this->assertMigrate('switch-read');
        $before = $this->meta();
        self::assertSame($this->prefix.'2', $this->aliases->writeTarget());

        $tester = $this->backfill($this->write);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame($before, $this->meta(), 'Żywy indeks w spoczynku: pieczęć ani kasowana, ani stawiana na nowo.');
        self::assertStringContainsString('nie jest celem migracji', $this->flat($tester));
    }

    public function test_pelny_backfill_do_przygotowanego_celu_przed_switch_write_nie_pieczetuje(): void
    {
        $this->assertMigrate('prepare');
        $this->catalogIds = range(1, 3);

        $tester = $this->backfill($this->prefix.'2');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertArrayNotHasKey('backfill_completed_at', $this->meta());
        self::assertStringContainsString('nie jest celem migracji', $this->flat($tester));
    }

    public function test_reindex_po_backfillu_zeruje_pieczec_backfillu(): void
    {
        $this->prepareTarget();
        $this->catalogIds = [1];
        $this->assertBackfill($this->prefix.'2');

        $this->assertMigrate('reindex', ['--wait' => true]);

        $meta = $this->meta();
        self::assertSame('reindex', $meta['fill_method']);
        self::assertArrayNotHasKey('backfill_completed_at', $meta);
        self::assertArrayHasKey('reindex_task', $meta);
    }

    private function prepareTarget(): void
    {
        $this->assertMigrate('prepare');
        $this->assertMigrate('switch-write');
    }

    /** @return array<string, mixed> */
    private function meta(string $index = ''): array
    {
        $index = $index !== '' ? $index : $this->prefix.'2';

        return $this->client->indices()->getMapping(['index' => $index])->asArray()[$index]['mappings']['_meta']['migration'] ?? [];
    }

    /** @param array<string, string|bool> $options */
    private function backfill(string $index, array $options = []): CommandTester
    {
        $migration = new IndexMigration($this->client, new IndexMapping($this->mappingsDir), new IndexGate($this->client, $this->catalog()), pollSeconds: 0.2);
        $tester = new CommandTester(new IndexBackfillCommand($this->client, $this->catalog(), $migration));
        $tester->execute(['--index' => $index, '--read-alias' => $this->read, '--write-alias' => $this->write] + $options);

        return $tester;
    }

    /** @param array<string, string|bool> $options */
    private function assertBackfill(string $index, array $options = []): void
    {
        $tester = $this->backfill($index, $options);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
    }

    /** @param array<string, string|bool> $options */
    private function assertMigrate(string $step, array $options = []): CommandTester
    {
        $tester = $this->migrate($step, $options);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), "{$step}: ".$tester->getDisplay());

        return $tester;
    }

    /** @param array<string, string|bool> $options */
    private function migrate(string $step, array $options = []): CommandTester
    {
        $migration = new IndexMigration($this->client, new IndexMapping($this->mappingsDir), new IndexGate($this->client, $this->catalog()), pollSeconds: 0.2);
        $tester = new CommandTester(new IndexMigrateCommand($this->client, $migration));
        $tester->execute(['step' => $step, '--read-alias' => $this->read, '--write-alias' => $this->write] + $options);

        return $tester;
    }

    private function catalog(): CatalogProjectionClient
    {
        return new CatalogProjectionClient('http://catalog.invalid', new TraceContext, new MockHttpClient(function (string $method, string $url): MockResponse {
            $path = parse_url($url, \PHP_URL_PATH);
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $json = ['response_headers' => ['content-type: application/json']];
            if (! str_ends_with($path, '/count')) {
                $this->requests[] = $path.(parse_url($url, \PHP_URL_QUERY) !== null ? '?'.parse_url($url, \PHP_URL_QUERY) : '');
            }

            if (str_ends_with($path, '/api/internal/products/count')) {
                return new MockResponse(json_encode(['count' => \count($this->catalogIds)]), $json);
            }
            if (str_ends_with($path, '/api/internal/products/ids')) {
                $after = isset($query['after']) ? (int) $query['after'] : 0;
                $rest = array_values(array_filter($this->catalogIds, static fn (int $id) => $id > $after));
                $page = \array_slice($rest, 0, (int) $query['limit']);

                return new MockResponse(json_encode(['ids' => $page, 'next_after' => \count($rest) > \count($page) ? end($page) : null]), $json);
            }
            if (str_ends_with($path, '/api/internal/products/changed')) {
                $after = isset($query['after']) ? (int) $query['after'] : 0;
                $rest = array_values(array_filter($this->changedIds, static fn (int $id) => $id > $after));
                $page = \array_slice($rest, 0, (int) $query['limit']);

                return new MockResponse(json_encode(['ids' => $page, 'next_after' => \count($rest) > \count($page) ? end($page) : null]), $json);
            }
            if (str_ends_with($path, '/api/internal/products/projections')) {
                $ids = array_map('intval', explode(',', (string) $query['ids']));
                if (array_intersect($ids, $this->failingIds) !== []) {
                    return new MockResponse('', ['http_code' => 500]);
                }
                $projectable = $this->catalogProjectable ?? $this->catalogIds;
                $at = self::PROJECTED_AT + max($ids);
                $items = [];
                foreach (array_intersect($ids, $projectable) as $id) {
                    $items[] = ['id' => $id, 'projected_at_us' => $at, 'version' => 1] + $this->product($id);
                }

                return new MockResponse(json_encode(['projected_at_us' => $at, 'items' => $items, 'missing' => array_values(array_diff($ids, $projectable))]), $json);
            }
            if (preg_match('#/api/internal/products/(\d+)/projection$#', $path, $m) === 1) {
                $id = (int) $m[1];
                if (\in_array($id, $this->failingIds, true)) {
                    return new MockResponse('', ['http_code' => 500]);
                }
                if (! \in_array($id, $this->catalogProjectable ?? $this->catalogIds, true)) {
                    return new MockResponse(json_encode(['projected_at_us' => self::PROJECTED_AT + $id]), ['http_code' => 404] + $json);
                }

                return new MockResponse(json_encode(['id' => $id, 'projected_at_us' => self::PROJECTED_AT + $id, 'version' => 1] + $this->product($id)), $json);
            }

            return new MockResponse('', ['http_code' => 404]);
        }));
    }

    private function flat(CommandTester $tester): string
    {
        return trim(preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }
}
