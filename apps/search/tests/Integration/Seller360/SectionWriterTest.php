<?php

namespace App\Tests\Integration\Seller360;

use App\Seller360\Rebuild\WriteTargets;
use App\Seller360\SectionMeta;
use App\Seller360\SectionWriter;
use App\Seller360\Seller360Metrics;
use App\Tests\Support\RealElasticsearch;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Writer sekcji seller-360 (ETAP 13) na ŻYWYM Elasticsearchu, na dwóch indeksach testowych
 * z mapowaniem szablonów (prywatnym i publicznym). Skrypt `seller360-apply-section` musi
 * już leżeć w klastrze (`make seller360-setup`), inaczej zapis kończy się błędem ES.
 */
#[Group('integration')]
final class SectionWriterTest extends TestCase
{
    use RealElasticsearch;

    private const DIR = '/infra/elasticsearch/seller360';

    private Client $client;
    private string $private;
    private string $public;
    private SectionWriter $writer;

    protected function setUp(): void
    {
        $this->client = $this->elasticsearchOrSkip();
        if (! is_dir(self::DIR)) {
            self::markTestSkipped('Brak '.self::DIR.' (montowany w kontenerze search).');
        }
        $suffix = uniqid();
        $this->private = "seller-360-test-{$suffix}";
        $this->public = "seller-360-public-test-{$suffix}";
        foreach ([$this->private => 'template-seller-360.json', $this->public => 'template-seller-360-public.json'] as $index => $file) {
            $template = json_decode(file_get_contents(self::DIR.'/'.$file), associative: true, flags: \JSON_THROW_ON_ERROR)['template'];
            $this->client->indices()->create(['index' => $index, 'body' => [
                'settings' => ['number_of_shards' => 1, 'number_of_replicas' => 0, 'analysis' => $template['settings']['analysis']],
                'mappings' => $template['mappings'],
            ]]);
        }

        $this->writer = new SectionWriter($this->client, new Seller360Metrics($this->createStub(\Redis::class), new NullLogger), $this->private, $this->public, refresh: true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->private ?? null, $this->public ?? null] as $index) {
            if ($index !== null) {
                $this->client->indices()->delete(['index' => $index, 'ignore_unavailable' => true]);
            }
        }
    }

    private function meta(int $version): SectionMeta
    {
        return new SectionMeta($version, new \DateTimeImmutable('2026-11-21T09:59:00+00:00'));
    }

    /** @return array<string, mixed>|null */
    private function source(string $index, int $id): ?array
    {
        try {
            return $this->client->get(['index' => $index, 'id' => (string) $id])->asArray()['_source'];
        } catch (\Throwable) {
            return null;
        }
    }

    public function testOlderVersionIsNoop(): void
    {
        self::assertSame('created', $this->writer->apply(5, 'profile', ['name' => 'Wersja 3', 'city' => 'Łódź'], $this->meta(3)));

        self::assertSame('noop', $this->writer->apply(5, 'profile', ['name' => 'Wersja 2', 'city' => 'Gdańsk'], $this->meta(2)));

        $profile = $this->source($this->private, 5)['profile'];
        self::assertSame('Wersja 3', $profile['name']);
        self::assertSame(3, $profile['_meta']['version']);
        self::assertSame('updated', $this->writer->apply(5, 'profile', ['name' => 'Wersja 4', 'city' => 'Łódź'], $this->meta(4)));
    }

    public function testPublicDocNeverContainsBilling(): void
    {
        $this->writer->apply(5, 'billing', ['balance_cents' => 100, 'open_invoices' => 1], $this->meta(1));

        self::assertNotNull($this->source($this->private, 5)['billing']);
        self::assertNull($this->source($this->public, 5), 'zapis billing nie tworzy dokumentu publicznego');

        $this->writer->apply(5, 'profile', ['name' => 'Sklep', 'city' => 'Łódź'], $this->meta(1), ['nip' => '1234563218', 'region' => 'łódzkie', 'synthetic' => false]);

        $public = $this->source($this->public, 5);
        self::assertSame('Sklep', $public['profile']['name']);
        self::assertSame('łódzkie', $public['region']);
        self::assertArrayNotHasKey('billing', $public);
        self::assertSame('łódzkie', $this->source($this->private, 5)['region']);
    }

    /** Ruling R8: kopia publiczna bez `nip` i `profile.status_reason` (pojedynczy zapis i `_bulk`); prywatna z nimi. */
    public function testPublicDocHasNoNipNorStatusReason(): void
    {
        $profile = ['name' => 'Sklep', 'city' => 'Łódź', 'status' => 'blocked', 'status_reason' => 'Notatka moderacji'];
        $doc = ['nip' => '1234563218', 'region' => 'łódzkie', 'synthetic' => true];
        $this->writer->apply(5, 'profile', $profile, $this->meta(1), $doc);
        $this->writer->applyMany([['sellerId' => 6, 'section' => 'profile', 'data' => $profile, 'meta' => $this->meta(1), 'docFields' => $doc]]);

        foreach ([5, 6] as $id) {
            $public = $this->source($this->public, $id);
            self::assertArrayNotHasKey('nip', $public, "public {$id}");
            self::assertArrayNotHasKey('status_reason', $public['profile'], "public {$id}");
            self::assertSame('łódzkie', $public['region']);
            self::assertTrue($public['synthetic']);
            $private = $this->source($this->private, $id);
            self::assertSame('1234563218', $private['nip']);
            self::assertSame('Notatka moderacji', $private['profile']['status_reason']);
        }
    }

    public function testSectionsAreIndependent(): void
    {
        $this->writer->apply(5, 'billing', ['balance_cents' => 100], $this->meta(9));
        $this->writer->apply(5, 'profile', ['name' => 'Sklep', 'city' => 'Łódź'], $this->meta(1));
        $this->writer->apply(5, 'offers', ['active' => 2, 'total' => 3], $this->meta(1));

        $source = $this->source($this->private, 5);
        self::assertSame(9, $source['billing']['_meta']['version']);
        self::assertSame(1, $source['profile']['_meta']['version']);
        self::assertSame(2, $source['offers']['active']);
        self::assertSame('5', $source['seller_id']);
    }

    public function testEmptyDataIsStillAnObject(): void
    {
        self::assertSame('created', $this->writer->apply(5, 'registry', [], $this->meta(1)));

        self::assertSame(1, $this->source($this->private, 5)['registry']['_meta']['version']);
    }

    public function testApplyManyWritesBothIndicesAndReportsPerItemResults(): void
    {
        $results = $this->writer->applyMany([
            ['sellerId' => 1, 'section' => 'profile', 'data' => ['name' => 'Jeden', 'city' => 'Łódź'], 'meta' => $this->meta(2), 'docFields' => ['synthetic' => true]],
            ['sellerId' => 2, 'section' => 'billing', 'data' => ['balance_cents' => 5], 'meta' => $this->meta(1), 'docFields' => []],
            ['sellerId' => 1, 'section' => 'profile', 'data' => ['name' => 'Stary', 'city' => 'Łódź'], 'meta' => $this->meta(1), 'docFields' => []],
        ]);

        self::assertSame(['created', 'created', 'noop'], $results);
        self::assertSame('Jeden', $this->source($this->public, 1)['profile']['name']);
        self::assertTrue($this->source($this->public, 1)['synthetic']);
        self::assertNull($this->source($this->public, 2));
        self::assertSame(5, $this->source($this->private, 2)['billing']['balance_cents']);
    }

    public function testWritesEverySectionToEveryTargetPairAndReturnsTheFirstPairsResult(): void
    {
        [$private2, $public2] = $this->secondPair();
        try {
            $targets = $this->targets();
            $targets->startDual($private2, $public2);
            $writer = new SectionWriter($this->client, new Seller360Metrics($this->createStub(\Redis::class), new NullLogger), refresh: true, targets: $targets);
            $writer->apply(5, 'billing', ['balance_cents' => 100], $this->meta(3));
            self::assertSame(3, $this->source($private2, 5)['billing']['_meta']['version']);

            // stary indeks ma już nowszą wersję, nowy nie: wynik to wynik pierwszej pary (aliasów)
            self::assertSame('noop', (new SectionWriter($this->client, new Seller360Metrics($this->createStub(\Redis::class), new NullLogger), $this->private, $this->public, refresh: true))
                ->apply(5, 'billing', ['balance_cents' => 100], $this->meta(3)));
            $this->client->delete(['index' => $private2, 'id' => '5', 'refresh' => 'true']);
            self::assertSame('noop', $writer->apply(5, 'billing', ['balance_cents' => 100], $this->meta(3)));
            self::assertSame(3, $this->source($private2, 5)['billing']['_meta']['version'], 'druga para dostaje zapis mimo noop w pierwszej');

            $results = $writer->applyMany([
                ['sellerId' => 6, 'section' => 'profile', 'data' => ['name' => 'Sześć', 'city' => 'Łódź'], 'meta' => $this->meta(1), 'docFields' => ['synthetic' => true]],
            ]);
            self::assertSame(['created'], $results);
            foreach ([$this->private, $private2, $this->public, $public2] as $index) {
                self::assertSame('Sześć', $this->source($index, 6)['profile']['name'], $index);
            }
        } finally {
            $this->client->indices()->delete(['index' => "{$private2},{$public2}", 'ignore_unavailable' => true]);
        }
    }

    public function testApplyManyReportsErrorWhenTheNewPairRejectsTheWrite(): void
    {
        $targets = $this->targets();
        $targets->startDual('seller-360-test-zamkniety-'.uniqid(), $this->public);
        $this->client->indices()->create(['index' => $closed = $targets->current()[1]['main'], 'body' => ['settings' => ['number_of_shards' => 1, 'number_of_replicas' => 0]]]);
        try {
            $this->client->indices()->close(['index' => $closed]);
            $writer = new SectionWriter($this->client, new Seller360Metrics($this->createStub(\Redis::class), new NullLogger), refresh: true, targets: $targets);

            self::assertSame(['error'], $writer->applyMany([
                ['sellerId' => 7, 'section' => 'billing', 'data' => ['balance_cents' => 1], 'meta' => $this->meta(1), 'docFields' => []],
            ]), 'nieudany zapis do nowego indeksu wraca jako error, żeby wołający ponowił');
        } finally {
            $this->client->indices()->delete(['index' => $closed, 'ignore_unavailable' => true]);
        }
    }

    /**
     * Brak aliasu zapisu: `require_alias` daje 404 `index_not_found_exception` (projektor ponawia), a nie
     * auto-utworzony zwykły indeks o nazwie aliasu z mapowaniem dynamicznym (RUNBOOK #150). Dotyczy zapisu
     * pojedynczego i `_bulk`.
     */
    public function testMissingWriteAliasIs404AndCreatesNoIndex(): void
    {
        $missing = 'seller-360-test-'.uniqid().'-write';
        $writer = (new SectionWriter($this->client, new Seller360Metrics($this->createStub(\Redis::class), new NullLogger), refresh: true))
            ->forPairs([['main' => $missing, 'public' => $missing.'-public-write']]);

        try {
            $writer->apply(5, 'profile', ['name' => 'Sklep'], $this->meta(1));
            self::fail('Oczekiwany 404');
        } catch (ClientResponseException $e) {
            self::assertSame(404, $e->getCode());
            self::assertStringContainsString('index_not_found_exception', (string) $e->getResponse()->getBody());
        }
        self::assertSame(['error'], $writer->applyMany([['sellerId' => 5, 'section' => 'offers', 'data' => ['active' => 1], 'meta' => $this->meta(1)]]));
        self::assertFalse($this->client->indices()->exists(['index' => $missing])->asBool(), 'ES nie może auto-utworzyć indeksu o nazwie aliasu');
    }

    private function targets(): WriteTargets
    {
        $redis = $this->createStub(\Redis::class);
        $redis->method('get')->willReturn(false);
        $redis->method('set')->willReturn(true);

        return new WriteTargets($redis, new NullLogger, null, 'rt:targets', $this->private, $this->public);
    }

    /** @return array{0: string, 1: string} */
    private function secondPair(): array
    {
        $suffix = uniqid();
        $pair = ["seller-360-test-{$suffix}-b", "seller-360-public-test-{$suffix}-b"];
        foreach (array_combine($pair, ['template-seller-360.json', 'template-seller-360-public.json']) as $index => $file) {
            $template = json_decode(file_get_contents(self::DIR.'/'.$file), associative: true, flags: \JSON_THROW_ON_ERROR)['template'];
            $this->client->indices()->create(['index' => $index, 'body' => [
                'settings' => ['number_of_shards' => 1, 'number_of_replicas' => 0, 'analysis' => $template['settings']['analysis']],
                'mappings' => $template['mappings'],
            ]]);
        }

        return $pair;
    }
}
