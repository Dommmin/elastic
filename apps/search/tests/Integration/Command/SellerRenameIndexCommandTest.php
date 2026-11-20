<?php

namespace App\Tests\Integration\Command;

use App\Command\SellerRenameIndexCommand;
use App\Service\ElasticsearchIndexer;
use App\Service\IndexAliases;
use App\Service\IndexOutcome;
use App\Service\SellerRenamer;
use App\Service\TaskWaiter;
use App\Tests\Support\RealElasticsearch;
use Elastic\Elasticsearch\Client;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

/**
 * `search:seller:rename` (spec 5.7) na prawdziwym ES, jednorazowy indeks
 * `products-seller-<uniq>`; żywych aliasów test nie dotyka.
 */
#[Group('integration')]
final class SellerRenameIndexCommandTest extends TestCase
{
    use RealElasticsearch;

    private const SELLER = '42';

    private Client $client;
    private string $index;
    /** Drugi indeks — cel zapisu w scenariuszu „migracja w toku”. */
    private string $other;
    private string $read;
    private string $write;

    protected function setUp(): void
    {
        $this->client = $this->elasticsearchOrSkip();
        $id = uniqid();
        $this->index = "products-seller-{$id}";
        $this->other = "products-seller-{$id}-next";
        $this->read = "products-seller-read-{$id}";
        $this->write = "products-seller-write-{$id}";
        $this->client->indices()->create(['index' => $this->index, 'body' => $this->mappingBody(1)]);
    }

    protected function tearDown(): void
    {
        if (isset($this->index)) {
            $this->client->indices()->delete(['index' => $this->index.','.$this->other, 'ignore_unavailable' => true]);
        }
    }

    public function test_odmawia_w_trakcie_migracji_gdy_aliasy_wskazuja_rozne_indeksy(): void
    {
        $this->seed(10, sellerEvery: 1);
        $this->client->indices()->create(['index' => $this->other, 'body' => $this->mappingBody(1)]);
        $aliases = new IndexAliases($this->client, $this->read, $this->write);
        $aliases->attach($this->read, $this->index);
        $aliases->attach($this->write, $this->other);

        foreach ([[], ['--check-only' => true]] as $options) {
            $tester = $this->rename('Nowa Nazwa', $options);

            self::assertSame(Command::FAILURE, $tester->getStatusCode());
            $flat = preg_replace('/\s+/', ' ', $tester->getDisplay());
            self::assertStringContainsString('trwa migracja', $flat);
            self::assertStringContainsString('make reindex albo make reindex-abort', $flat);
            self::assertStringNotContainsString('TASK=', $tester->getDisplay());
        }
        $this->client->indices()->refresh(['index' => $this->index]);
        self::assertSame(10, $this->countSeller('Stara Nazwa'));
    }

    public function test_check_only_przechodzi_bez_migracji_i_niczego_nie_zmienia(): void
    {
        $this->seed(10, sellerEvery: 1);
        $aliases = new IndexAliases($this->client, $this->read, $this->write);
        $aliases->attach($this->read, $this->index);
        $aliases->attach($this->write, $this->index);

        $tester = $this->rename('Nowa Nazwa', ['--check-only' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringNotContainsString('TASK=', $tester->getDisplay());
        $this->client->indices()->refresh(['index' => $this->index]);
        self::assertSame(10, $this->countSeller('Stara Nazwa'));
    }

    public function test_zmienia_tylko_oferty_sprzedawcy_a_powtorka_nic_nie_robi(): void
    {
        $this->seed(300, sellerEvery: 5);

        $tester = $this->rename('Nowa Nazwa');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertMatchesRegularExpression('/^TASK=\S+:\d+$/m', $tester->getDisplay());
        self::assertStringContainsString('zakończono: updated=60 version_conflicts=0 total=60', $tester->getDisplay());
        $this->client->indices()->refresh(['index' => $this->index]);
        self::assertSame(60, $this->countSeller('Nowa Nazwa'));
        self::assertSame(0, $this->countSeller('Stara Nazwa'));
        // Oferta innego sprzedawcy w tym samym dokumencie i dokumenty bez oferty — bez zmian.
        self::assertSame(300, $this->countSeller('Inny', sellerId: '7') + 240);
        $doc = $this->client->get(['index' => $this->index, 'id' => '5'])->asArray()['_source'];
        self::assertSame(['Nowa Nazwa', 'Inny'], array_column($doc['offers'], 'seller'));

        $again = $this->rename('Nowa Nazwa');

        self::assertStringContainsString('zakończono: updated=0 version_conflicts=0 total=0', $again->getDisplay());
    }

    public function test_dokument_po_update_by_query_ma_wersje_plus_1_a_pozniejsze_zdarzenie_wygrywa(): void
    {
        $v = 1_790_000_000_000_000;
        $indexer = new ElasticsearchIndexer($this->client, $this->index);
        self::assertSame(IndexOutcome::Indexed, $indexer->indexProduct(['id' => 5] + $this->withSeller(5), $v));

        $this->rename('Nowa Nazwa');

        $doc = $this->client->get(['index' => $this->index, 'id' => '5'])->asArray();
        self::assertSame($v + 1, $doc['_version']);
        self::assertSame('Nowa Nazwa', $doc['_source']['offers'][0]['seller']);
        // Zdarzenie sprzed zmiany (V) przegrywa, późniejsze (V+1000) wygrywa.
        self::assertSame(IndexOutcome::Stale, $indexer->indexProduct(['id' => 5] + $this->withSeller(5), $v));
        self::assertSame(IndexOutcome::Indexed, $indexer->indexProduct(['id' => 5] + $this->withSeller(5, 'Z zdarzenia'), $v + 1_000));
        self::assertSame($v + 1_000, $this->client->get(['index' => $this->index, 'id' => '5'])->asArray()['_version']);
    }

    /**
     * Konflikt wersji: dokument zmieniony między odczytem scrolla a zapisem
     * `_update_by_query` (`conflicts: proceed` go pomija). Kod 0, ale z ostrzeżeniem
     * „uruchom ponownie” — powtórka poprawi resztę (zapytanie jest idempotentne).
     * Komenda w osobnym procesie, żeby test mógł zmienić dokument w trakcie:
     * rps=100 przy paczkach po 1000 = ~10 s przerwy przed drugą paczką.
     */
    public function test_konflikty_wersji_koncza_sie_ostrzezeniem_a_powtorka_poprawia_reszte(): void
    {
        $this->seed(1500, sellerEvery: 1);
        $process = new Process(
            ['php', 'bin/console', 'search:seller:rename', self::SELLER, 'Nowa Nazwa', '--index='.$this->index, '--rps=100', '--read-alias='.$this->read, '--write-alias='.$this->write],
            \dirname(__DIR__, 3),
            ['SHELL_VERBOSITY' => '0', 'COLUMNS' => '200'],
            timeout: 120,
        );
        $process->start();

        // Czekamy, aż pierwsza paczka (1000) przejdzie, i zmieniamy dokument z drugiej.
        for ($i = 0; $i < 100 && ! str_contains($process->getOutput(), 'TASK='); ++$i) {
            usleep(100_000);
        }
        usleep(1_500_000);
        $this->client->index(['index' => $this->index, 'id' => '1400', 'version' => 2_000_000, 'version_type' => 'external', 'body' => $this->withSeller(1400)]);
        $process->wait();

        $output = preg_replace('/\s+/', ' ', $process->getOutput().$process->getErrorOutput());
        self::assertSame(Command::SUCCESS, $process->getExitCode(), $output);
        self::assertMatchesRegularExpression('/zakończono: updated=1499 version_conflicts=1 total=1500/', $output);
        self::assertStringContainsString('uruchom ponownie', $output);

        $again = $this->rename('Nowa Nazwa');
        self::assertStringContainsString('zakończono: updated=1 version_conflicts=0 total=1', $again->getDisplay());
        self::assertStringNotContainsString('uruchom ponownie', preg_replace('/\s+/', ' ', $again->getDisplay()));
    }

    public function test_no_wait_zwraca_task_ktory_konczy_sie_w_tasks_api(): void
    {
        $this->seed(40, sellerEvery: 2);

        $tester = $this->rename('Nowa Nazwa', ['--no-wait' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        preg_match('/^TASK=(\S+)$/m', $tester->getDisplay(), $m);
        self::assertStringNotContainsString('zakończono', $tester->getDisplay());
        $outcome = (new TaskWaiter($this->client, 0.1))->wait($m[1], static fn () => null, static fn () => false);
        self::assertSame(20, $outcome['result']['updated']);
    }

    public function test_zle_argumenty_sa_odrzucane_bez_dotykania_es(): void
    {
        $this->seed(10, sellerEvery: 1);

        foreach ([['  ', []], ['X', ['--rps' => 'szybko']]] as [$name, $options]) {
            self::assertSame(Command::FAILURE, $this->rename($name, $options)->getStatusCode());
        }
        self::assertSame(
            Command::FAILURE,
            $this->tester()->execute(['seller_id' => 'abc', 'name' => 'X', '--index' => $this->index, '--read-alias' => $this->read, '--write-alias' => $this->write]),
        );
        $this->client->indices()->refresh(['index' => $this->index]);
        self::assertSame(10, $this->countSeller('Stara Nazwa'));
    }

    /** @param array<string, string|bool> $options */
    private function rename(string $name, array $options = []): CommandTester
    {
        $tester = $this->tester();
        $tester->execute(array_merge(['seller_id' => self::SELLER, 'name' => $name, '--index' => $this->index, '--rps' => '-1', '--read-alias' => $this->read, '--write-alias' => $this->write], $options));

        return $tester;
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new SellerRenameIndexCommand(new SellerRenamer($this->client), new TaskWaiter($this->client, 0.1), $this->client));
    }

    /** Co `$sellerEvery`-ty produkt ma ofertę sprzedawcy 42 („Stara Nazwa”) + ofertę sprzedawcy 7 („Inny”). */
    private function seed(int $n, int $sellerEvery): void
    {
        $body = [];
        for ($id = 1; $id <= $n; $id++) {
            $body[] = ['index' => ['_index' => $this->index, '_id' => (string) $id, 'version' => 1_000_000 + $id, 'version_type' => 'external']];
            $body[] = $id % $sellerEvery === 0 ? $this->withSeller($id) : $this->product($id);
        }
        $response = $this->client->bulk(['body' => $body, 'refresh' => 'true'])->asArray();
        self::assertFalse($response['errors']);
    }

    /** @return array<string, mixed> */
    private function withSeller(int $id, string $name = 'Stara Nazwa'): array
    {
        return ['offers' => [
            ['offer_id' => "o{$id}", 'seller_id' => self::SELLER, 'seller' => $name, 'price' => 19.99, 'stock' => 1],
            ['offer_id' => "p{$id}", 'seller_id' => '7', 'seller' => 'Inny', 'price' => 19.99, 'stock' => 1],
        ]] + $this->product($id);
    }

    private function countSeller(string $name, string $sellerId = self::SELLER): int
    {
        return (int) $this->client->count(['index' => $this->index, 'body' => ['query' => ['nested' => [
            'path' => 'offers',
            'query' => ['bool' => ['filter' => [
                ['term' => ['offers.seller_id' => $sellerId]],
                ['term' => ['offers.seller' => $name]],
            ]]],
        ]]]])->asArray()['count'];
    }
}
