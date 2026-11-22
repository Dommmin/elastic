<?php

namespace App\Tests\Integration\Seller360;

use App\Seller360\SectionMeta;
use App\Seller360\SectionWriter;
use App\Seller360\Seller360Metrics;
use App\Tests\Support\RealElasticsearch;
use Elastic\Elasticsearch\Client;
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
}
