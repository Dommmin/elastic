<?php

namespace App\Tests\Integration\Command;

use App\Command\CreateSearchIndexCommand;
use App\Service\ElasticsearchClientFactory;
use App\Service\IndexAliases;
use Elastic\Elasticsearch\Client;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `search:index:create` na PRAWDZIWYM Elasticsearchu, na jednorazowych
 * aliasach i indeksach (`products-ct-<uniq>-vN`, aliasy `products-ct-read-*`/`-write-*` — rola ES pozwala tylko na `products-*`) — żywych `products-search`
 * i `products-write` test nie dotyka.
 */
#[Group('integration')]
final class CreateSearchIndexCommandTest extends TestCase
{
    private Client $client;
    private string $read;
    private string $write;
    private string $prefix;
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

        $id = uniqid();
        $this->read = "products-ct-read-{$id}";
        $this->write = "products-ct-write-{$id}";
        $this->prefix = "products-ct-{$id}-v";
        $this->aliases = new IndexAliases($this->client, $this->read, $this->write);
    }

    protected function tearDown(): void
    {
        if (isset($this->prefix)) {
            $this->client->indices()->delete(['index' => $this->prefix.'1,'.$this->prefix.'2', 'ignore_unavailable' => true]);
        }
    }

    public function test_swiezy_klaster_dostaje_oba_aliasy_na_v1_z_write_index(): void
    {
        $tester = $this->run_();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame($this->prefix.'1', $this->aliases->readTarget());
        self::assertSame($this->prefix.'1', $this->aliases->writeTarget());

        $alias = $this->client->indices()->getAlias(['name' => $this->write])->asArray();
        self::assertTrue($alias[$this->prefix.'1']['aliases'][$this->write]['is_write_index']);
    }

    public function test_drugi_przebieg_jest_idempotentny(): void
    {
        $this->run_();
        $tester = $this->run_();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame($this->prefix.'1', $this->aliases->readTarget());
        self::assertSame($this->prefix.'1', $this->aliases->writeTarget());
    }

    public function test_jest_tylko_alias_odczytu_to_dopina_zapis_na_ten_sam_indeks(): void
    {
        $this->client->indices()->create(['index' => $this->prefix.'1']);
        $this->client->indices()->updateAliases(['body' => ['actions' => [
            ['add' => ['index' => $this->prefix.'1', 'alias' => $this->read]],
        ]]]);
        self::assertNull($this->aliases->writeTarget());

        $tester = $this->run_();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame($this->prefix.'1', $this->aliases->readTarget());
        self::assertSame($this->prefix.'1', $this->aliases->writeTarget());
    }

    public function test_nie_przepina_istniejacych_aliasow_przy_nowej_wersji(): void
    {
        $this->run_();
        $tester = $this->run_(['--index-version' => '2']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertTrue($this->client->indices()->exists(['index' => $this->prefix.'2'])->asBool());
        self::assertSame($this->prefix.'1', $this->aliases->readTarget());
        self::assertSame($this->prefix.'1', $this->aliases->writeTarget());
    }

    public function test_version_of_i_swap(): void
    {
        self::assertSame(7, IndexAliases::versionOf('products-v7'));

        $this->run_();
        $this->run_(['--index-version' => '2']);
        $this->aliases->swap($this->write, $this->prefix.'1', $this->prefix.'2');

        self::assertSame($this->prefix.'2', $this->aliases->writeTarget());
        self::assertSame($this->prefix.'1', $this->aliases->readTarget());
    }

    /** @param array<string, string> $options */
    private function run_(array $options = []): CommandTester
    {
        $tester = new CommandTester(new CreateSearchIndexCommand(
            $this->client,
            new IndexAliases($this->client, $this->read, $this->write),
            indexPrefix: $this->prefix,
        ));
        $tester->execute($options);

        return $tester;
    }

    private function env(string $name): string
    {
        return (string) ($_SERVER[$name] ?? getenv($name) ?: '');
    }
}
