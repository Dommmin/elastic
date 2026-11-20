<?php

namespace App\Tests\Integration\Command;

use App\Command\IndexMigrateCommand;
use App\Service\ElasticsearchIndexer;
use App\Service\IndexAliases;
use App\Service\IndexGate;
use App\Service\IndexMapping;
use App\Service\IndexMigration;
use App\Tests\Support\RealElasticsearch;
use Elastic\Elasticsearch\Client;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `search:index:migrate <krok>` (spec ETAP 10, 5.3–5.5, 8) na prawdziwym ES.
 *
 * Indeksy `products-mig-<uniq>-vN`, aliasy `products-mig-read-<uniq>` /
 * `products-mig-write-<uniq>` — żywych `products-search`/`products-write`/
 * `products-v1` test nie dotyka. Katalog mapowań jest tymczasowy: v1 i v2
 * z repo, v3 = kopia v2 (drugi przebieg migracji potrzebuje `products-v3.json`).
 */
#[Group('integration')]
final class IndexMigrateCommandTest extends TestCase
{
    use RealElasticsearch;

    private const DOCS = 2000;

    private Client $client;
    private string $prefix;
    private string $read;
    private string $write;
    private string $mappingsDir;
    private IndexAliases $aliases;
    private int $catalogCount = self::DOCS;
    /** @var list<string> dodatkowe jednorazowe indeksy do usunięcia w tearDown */
    private array $extraIndices = [];

    protected function setUp(): void
    {
        $this->client = $this->elasticsearchOrSkip();

        $id = uniqid();
        $this->prefix = "products-mig-{$id}-v";
        $this->read = "products-mig-read-{$id}";
        $this->write = "products-mig-write-{$id}";
        $this->aliases = new IndexAliases($this->client, $this->read, $this->write);

        $this->mappingsDir = sys_get_temp_dir()."/mig-mappings-{$id}";
        mkdir($this->mappingsDir);
        copy(self::MAPPINGS_DIR.'/products-v1.json', $this->mappingsDir.'/products-v1.json');
        copy(self::MAPPINGS_DIR.'/products-v2.json', $this->mappingsDir.'/products-v2.json');
        copy(self::MAPPINGS_DIR.'/products-v2.json', $this->mappingsDir.'/products-v3.json');

        // Stan wyjściowy jak na żywym klastrze: v1 z dokumentami pod oboma aliasami.
        $this->client->indices()->create(['index' => $this->prefix.'1', 'body' => $this->mappingBody(1)]);
        $this->bulkProducts($this->client, $this->prefix.'1', 1, self::DOCS);
        $this->aliases->attach($this->read, $this->prefix.'1');
        $this->aliases->attach($this->write, $this->prefix.'1');
    }

    protected function tearDown(): void
    {
        if (! isset($this->prefix)) {
            return;
        }
        foreach ([...array_map(fn (int $v) => $this->prefix.$v, [1, 2, 3]), ...$this->extraIndices] as $index) {
            $this->client->indices()->delete(['index' => $index, 'ignore_unavailable' => true]);
        }
        foreach (glob($this->mappingsDir.'/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->mappingsDir);
    }

    public function test_pelny_przebieg_odczyt_zawsze_pelny_a_na_koncu_oba_aliasy_na_v2(): void
    {
        $v1 = $this->prefix.'1';
        $v2 = $this->prefix.'2';

        $prepare = $this->migrate('prepare');
        self::assertSame(Command::SUCCESS, $prepare->getStatusCode(), $prepare->getDisplay());
        self::assertStringContainsString("TARGET={$v2}", $prepare->getDisplay());
        $settings = $this->settings($v2);
        self::assertSame('0', $settings['number_of_replicas']);
        self::assertSame('-1', $settings['refresh_interval']);
        self::assertSame('1h', $settings['gc_deletes']);
        $this->assertReadIsFull();

        $this->assertStep('switch-write');
        self::assertSame($v2, $this->aliases->writeTarget());
        self::assertSame($v1, $this->aliases->readTarget());
        $this->assertReadIsFull();

        $reindex = $this->assertStep('reindex', ['--wait' => true]);
        self::assertMatchesRegularExpression('/TASK=\S+:\d+/', $reindex->getDisplay());
        self::assertMatchesRegularExpression('/created=2000\b/', $reindex->getDisplay());
        $this->assertReadIsFull();

        $this->assertStep('finalize');
        $settings = $this->settings($v2);
        self::assertSame('1', $settings['number_of_replicas']);
        self::assertSame('1s', $settings['refresh_interval']);
        $this->assertReadIsFull();

        $switch = $this->assertStep('switch-read');
        self::assertStringContainsString('GATE=PASS', $switch->getDisplay());
        self::assertSame($v2, $this->aliases->readTarget());
        self::assertSame($v2, $this->aliases->writeTarget());
        self::assertArrayNotHasKey('gc_deletes', $this->settings($v2), 'gc_deletes wraca do domyślnego po przełączeniu odczytu.');
        $this->assertReadIsFull();
        self::assertTrue($this->client->indices()->exists(['index' => $v1])->asBool(), 'vN zostaje do reindex-cleanup.');
    }

    public function test_zapis_przez_alias_po_switch_write_trafia_do_v2_i_reindeks_go_nie_nadpisuje(): void
    {
        $this->assertStep('prepare');
        $this->assertStep('switch-write');

        $fresh = ['id' => 7, 'projected_at_us' => 9_000_000, 'version' => 2] + $this->product(7, 'Nazwa ze strumienia');
        (new ElasticsearchIndexer($this->client, $this->write))->indexProduct($fresh, 9_000_000);

        $this->assertStep('reindex', ['--wait' => true]);
        $this->client->indices()->refresh(['index' => $this->prefix.'2']);

        $doc = $this->client->get(['index' => $this->prefix.'2', 'id' => '7'])->asArray();
        self::assertSame('Nazwa ze strumienia', $doc['_source']['name']);
        self::assertSame(9_000_000, $doc['_version']);
        // Stary indeks zamarzł na wersji sprzed przepięcia.
        self::assertSame('Produkt 7', $this->client->get(['index' => $this->prefix.'1', 'id' => '7'])->asArray()['_source']['name']);
        self::assertSame(self::DOCS, $this->countDocs($this->client, $this->prefix.'2'));
    }

    public function test_drugi_przebieg_z_v2_liczy_v3(): void
    {
        $this->fullMigration();
        self::assertSame($this->prefix.'2', $this->aliases->readTarget());

        $prepare = $this->assertStep('prepare');
        self::assertStringContainsString('TARGET='.$this->prefix.'3', $prepare->getDisplay());
        $this->assertStep('switch-write');
        $this->assertStep('reindex', ['--wait' => true]);
        $this->assertStep('finalize');
        $this->assertStep('switch-read');

        self::assertSame($this->prefix.'3', $this->aliases->readTarget());
        self::assertSame($this->prefix.'3', $this->aliases->writeTarget());
        self::assertSame(self::DOCS, $this->countDocs($this->client, $this->read));
    }

    public function test_prepare_odmawia_gdy_aliasy_wskazuja_rozne_indeksy(): void
    {
        $this->client->indices()->create(['index' => $this->prefix.'2', 'body' => $this->mappingBody(2)]);
        $this->aliases->swap($this->write, $this->prefix.'1', $this->prefix.'2');

        $tester = $this->migrate('prepare');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('różne indeksy', $this->flat($tester));
        self::assertStringNotContainsString('TARGET=', $tester->getDisplay());
    }

    public function test_prepare_odmawia_gdy_cel_istnieje_a_resume_przyjmuje_tylko_wlasny_cel(): void
    {
        $this->assertStep('prepare');

        $again = $this->migrate('prepare');
        self::assertSame(Command::FAILURE, $again->getStatusCode());
        self::assertStringContainsString('--resume', $this->flat($again));

        $resumed = $this->assertStep('prepare', ['--resume' => true]);
        self::assertStringContainsString('TARGET='.$this->prefix.'2', $resumed->getDisplay());
    }

    public function test_resume_odmawia_dla_obcego_indeksu_o_nazwie_celu(): void
    {
        // Ktoś utworzył v2 ręcznie (search:index:create) — to nie jest cel naszej migracji.
        $this->client->indices()->create(['index' => $this->prefix.'2', 'body' => $this->mappingBody(2)]);

        $tester = $this->migrate('prepare', ['--resume' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringNotContainsString('TARGET=', $tester->getDisplay());
    }

    public function test_prepare_odmawia_bez_pliku_mapowania_celu(): void
    {
        unlink($this->mappingsDir.'/products-v2.json');

        $tester = $this->migrate('prepare');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('products-v2.json', $this->flat($tester));
        self::assertFalse($this->client->indices()->exists(['index' => $this->prefix.'2'])->asBool());
    }

    public function test_reindex_przerwany_ctrl_c_i_powtorzony_daje_te_same_dokumenty_bez_dubli(): void
    {
        $this->assertStep('prepare');
        $this->assertStep('switch-write');

        // Ctrl-C w trakcie `--wait`: SIGALRM po 1 s wysyła SIGINT do tego procesu.
        // rps=100 przy paczkach po 1000 = ~10 s przerwy po pierwszej paczce,
        // więc zadanie na pewno jeszcze trwa.
        pcntl_async_signals(true);
        pcntl_signal(\SIGALRM, static fn () => posix_kill(getmypid(), \SIGINT));
        pcntl_alarm(1);
        try {
            $interrupted = $this->migrate('reindex', ['--wait' => true, '--rps' => '100']);
        } finally {
            pcntl_alarm(0);
            pcntl_signal(\SIGALRM, \SIG_DFL);
        }

        self::assertSame(130, $interrupted->getStatusCode(), $interrupted->getDisplay());
        self::assertMatchesRegularExpression('/anulowa/i', $interrupted->getDisplay());
        $this->client->indices()->refresh(['index' => $this->prefix.'2']);
        $partial = $this->countDocs($this->client, $this->prefix.'2');
        self::assertLessThan(self::DOCS, $partial, 'Anulowanie miało przerwać kopiowanie w połowie.');

        $again = $this->assertStep('reindex', ['--wait' => true]);
        // Już skopiowane dokumenty to konflikty wersji (ta sama wersja), nie duble.
        self::assertMatchesRegularExpression('/version_conflicts='.$partial.'\b/', $again->getDisplay());
        $this->client->indices()->refresh(['index' => $this->prefix.'2']);
        self::assertSame(self::DOCS, $this->countDocs($this->client, $this->prefix.'2'));
    }

    public function test_reindex_bez_wait_wypisuje_task_i_konczy_sukcesem(): void
    {
        $this->assertStep('prepare');
        $this->assertStep('switch-write');

        $tester = $this->assertStep('reindex');

        self::assertMatchesRegularExpression('/^TASK=(\S+:\d+)$/m', $tester->getDisplay());
    }

    public function test_reindex_odmawia_przed_switch_write(): void
    {
        $this->assertStep('prepare');

        $tester = $this->migrate('reindex');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringNotContainsString('TASK=', $tester->getDisplay());
    }

    public function test_abort_przywraca_oba_aliasy_na_v1_i_drukuje_od_kiedy_reprojekcja(): void
    {
        $this->assertStep('prepare');
        $before = new \DateTimeImmutable('-1 second');
        $this->assertStep('switch-write');
        $this->assertStep('reindex', ['--wait' => true]);
        $this->assertStep('finalize');

        $abort = $this->assertStep('abort');

        self::assertSame($this->prefix.'1', $this->aliases->readTarget());
        self::assertSame($this->prefix.'1', $this->aliases->writeTarget());
        self::assertMatchesRegularExpression('/^REPROJECT_NEEDED_SINCE=(\S+)$/m', $abort->getDisplay());
        preg_match('/^REPROJECT_NEEDED_SINCE=(\S+)$/m', $abort->getDisplay(), $m);
        $since = new \DateTimeImmutable($m[1]);
        self::assertGreaterThanOrEqual($before, $since);
        self::assertLessThanOrEqual(new \DateTimeImmutable('+1 second'), $since);
        self::assertTrue($this->client->indices()->exists(['index' => $this->prefix.'2'])->asBool(), 'vN+1 zostaje do diagnostyki.');
        // Podpowiedź nadrabiania: make reindex-abort robi to sam; ręcznie backfill --since.
        // Nie odsyłamy już do search:reproject (pełny O(N), sprzed Task 12b).
        $display = preg_replace('/\s+/', ' ', $abort->getDisplay());
        self::assertStringContainsString('make reindex-abort', $display);
        self::assertStringContainsString('search:index:backfill --index='.$this->aliases->write.' --since=', $display);
        self::assertStringNotContainsString('search:reproject', $display);
    }

    public function test_switch_read_przy_niezdanej_bramce_odmawia_i_nie_rusza_aliasu_odczytu(): void
    {
        $this->assertStep('prepare');
        $this->assertStep('switch-write');
        $this->assertStep('reindex', ['--wait' => true]);
        $this->assertStep('finalize');

        // Catalog ma o wiele więcej produktów, niż trafiło do vN+1.
        $this->catalogCount = (int) (self::DOCS / 0.3);
        $tester = $this->migrate('switch-read');

        self::assertNotSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('GATE=FAIL', $tester->getDisplay());
        self::assertSame($this->prefix.'1', $this->aliases->readTarget());
        self::assertSame($this->prefix.'2', $this->aliases->writeTarget());
    }

    public function test_switch_read_odmawia_przed_switch_write(): void
    {
        $this->assertStep('prepare');

        $tester = $this->migrate('switch-read');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame($this->prefix.'1', $this->aliases->readTarget());
    }

    public function test_cleanup_bez_yes_tylko_pokazuje_a_z_yes_usuwa_indeksy_bez_aliasu(): void
    {
        $this->fullMigration();

        $preview = $this->assertStep('cleanup');
        self::assertStringContainsString($this->prefix.'1', $preview->getDisplay());
        self::assertTrue($this->client->indices()->exists(['index' => $this->prefix.'1'])->asBool());

        $this->assertStep('cleanup', ['--yes' => true]);
        self::assertFalse($this->client->indices()->exists(['index' => $this->prefix.'1'])->asBool());
        self::assertTrue($this->client->indices()->exists(['index' => $this->prefix.'2'])->asBool());
    }

    public function test_switch_read_bez_finalize_odmawia(): void
    {
        $this->assertStep('prepare');
        $this->assertStep('switch-write');
        $this->assertStep('reindex', ['--wait' => true]);

        // Przy 0 replik indeks jest zielony, a bramka sama odświeża — liczba się zgadza.
        $tester = $this->migrate('switch-read');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('finalize', $this->flat($tester));
        self::assertSame($this->prefix.'1', $this->aliases->readTarget());
    }

    public function test_finalize_bez_reindeksu_odmawia(): void
    {
        $this->assertStep('prepare');
        $this->assertStep('switch-write');

        $tester = $this->migrate('finalize');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('reindex', $this->flat($tester));
        self::assertSame('0', $this->settings($this->prefix.'2')['number_of_replicas']);
    }

    public function test_anulowany_reindeks_blokuje_finalize_i_switch_read(): void
    {
        $this->assertStep('prepare');
        $this->assertStep('switch-write');
        $taskId = $this->startThrottledReindex();

        $this->client->tasks()->cancel(['task_id' => $taskId]);
        $this->waitUntilCompleted($taskId);

        $finalize = $this->migrate('finalize');
        self::assertSame(Command::FAILURE, $finalize->getStatusCode());
        self::assertStringContainsString('anulowane', $this->flat($finalize));

        // Nawet ręcznie "sfinalizowany" indeks nie przejdzie: wynik zadania się nie zmienił.
        $this->client->indices()->putSettings(['index' => $this->prefix.'2', 'body' => ['index' => ['number_of_replicas' => 1, 'refresh_interval' => '1s']]]);
        $switch = $this->migrate('switch-read');
        self::assertSame(Command::FAILURE, $switch->getStatusCode());
        self::assertStringContainsString('anulowane', $this->flat($switch));
        self::assertSame($this->prefix.'1', $this->aliases->readTarget());
    }

    public function test_reindex_i_abort_odmawiaja_gdy_reindeks_trwa(): void
    {
        $this->assertStep('prepare');
        $this->assertStep('switch-write');
        $taskId = $this->startThrottledReindex();

        try {
            $again = $this->migrate('reindex');
            self::assertSame(Command::FAILURE, $again->getStatusCode());
            self::assertStringContainsString('trwa', $this->flat($again));

            $abort = $this->migrate('abort');
            self::assertSame(Command::FAILURE, $abort->getStatusCode());
            self::assertSame($this->prefix.'2', $this->aliases->writeTarget());
        } finally {
            $this->client->tasks()->cancel(['task_id' => $taskId]);
            $this->waitUntilCompleted($taskId);
        }
    }

    public function test_resume_po_abort_odmawia(): void
    {
        $this->assertStep('prepare');
        $this->assertStep('switch-write');
        $this->assertStep('abort');

        $tester = $this->migrate('prepare', ['--resume' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Usuń '.$this->prefix.'2', $this->flat($tester));
        self::assertStringNotContainsString('TARGET=', $tester->getDisplay());
    }

    public function test_switch_read_dokancza_po_awarii_po_przepieciu_a_abort_juz_odmawia(): void
    {
        $this->assertStep('prepare');
        $this->assertStep('switch-write');
        $this->assertStep('reindex', ['--wait' => true]);
        $this->assertStep('finalize');

        // Symulacja: proces padł zaraz po przepięciu odczytu (znacznik startu jest, końca nie).
        $mapping = $this->client->indices()->getMapping(['index' => $this->prefix.'2'])->asArray();
        $meta = $mapping[$this->prefix.'2']['mappings']['_meta'];
        $meta['migration']['switch_read_started_at'] = '2026-11-19T12:00:00+00:00';
        $this->client->indices()->putMapping(['index' => $this->prefix.'2', 'body' => ['_meta' => $meta]]);
        $this->aliases->swap($this->read, $this->prefix.'1', $this->prefix.'2');

        self::assertSame(Command::FAILURE, $this->migrate('abort')->getStatusCode());

        $this->assertStep('switch-read');
        self::assertArrayNotHasKey('gc_deletes', $this->settings($this->prefix.'2'));
        self::assertSame($this->prefix.'2', $this->aliases->readTarget());
    }

    public function test_alias_na_indeksie_bez_przyrostka_wersji_to_czytelna_odmowa(): void
    {
        $plain = $this->prefix.'1-plain';
        $this->extraIndices[] = $plain;
        $this->client->indices()->create(['index' => $plain, 'body' => $this->mappingBody(1)]);
        $this->aliases->swap($this->read, $this->prefix.'1', $plain);
        $this->aliases->swap($this->write, $this->prefix.'1', $plain);

        $tester = $this->migrate('prepare');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('-vN', $this->flat($tester));
    }

    public function test_cleanup_pomija_przygotowany_cel_bez_switch_write(): void
    {
        $this->fullMigration();
        $this->assertStep('prepare'); // v3, jeszcze bez switch-write

        $this->assertStep('cleanup', ['--yes' => true]);

        self::assertFalse($this->client->indices()->exists(['index' => $this->prefix.'1'])->asBool());
        self::assertTrue($this->client->indices()->exists(['index' => $this->prefix.'3'])->asBool());
    }

    /** `reindex` bez --wait z rps=100: pierwsza paczka 1000, potem ~10 s przerwy — zadanie na pewno trwa. */
    private function startThrottledReindex(): string
    {
        $tester = $this->assertStep('reindex', ['--rps' => '100']);
        preg_match('/^TASK=(\S+)$/m', $tester->getDisplay(), $m);

        return $m[1];
    }

    private function waitUntilCompleted(string $taskId): void
    {
        for ($i = 0; $i < 100; ++$i) {
            if ($this->client->tasks()->get(['task_id' => $taskId])->asArray()['completed'] ?? false) {
                return;
            }
            usleep(100_000);
        }
        self::fail("Zadanie {$taskId} nie skończyło się w 10 s.");
    }

    private function fullMigration(): void
    {
        foreach (['prepare', 'switch-write'] as $step) {
            $this->assertStep($step);
        }
        $this->assertStep('reindex', ['--wait' => true]);
        $this->assertStep('finalize');
        $this->assertStep('switch-read');
    }

    private function assertReadIsFull(): void
    {
        self::assertSame(self::DOCS, $this->countDocs($this->client, $this->read), 'Alias odczytu musi cały czas wskazywać pełny indeks.');
    }

    /** @return array<string, mixed> ustawienia `index.*` (bez prefiksu) */
    private function settings(string $index): array
    {
        return $this->client->indices()->getSettings(['index' => $index])->asArray()[$index]['settings']['index'];
    }

    /** @param array<string, string|bool> $options */
    private function assertStep(string $step, array $options = []): CommandTester
    {
        $tester = $this->migrate($step, $options);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), "{$step}: ".$tester->getDisplay());

        return $tester;
    }

    /** @param array<string, string|bool> $options */
    private function migrate(string $step, array $options = []): CommandTester
    {
        $migration = new IndexMigration(
            $this->client,
            new IndexMapping($this->mappingsDir),
            new IndexGate($this->client, $this->fakeCatalog(fn () => $this->catalogCount)),
            pollSeconds: 0.2,
        );
        $tester = new CommandTester(new IndexMigrateCommand($this->client, $migration));
        $tester->execute(['step' => $step, '--read-alias' => $this->read, '--write-alias' => $this->write] + $options);

        return $tester;
    }

    /** Symfony zawija długie komunikaty w ramce — porównujemy po spłaszczeniu białych znaków. */
    private function flat(CommandTester $tester): string
    {
        return trim(preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }
}
