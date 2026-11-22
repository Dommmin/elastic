<?php

namespace App\Tests\Integration\Seller360;

use App\Seller360\Rebuild\WriteTargets;
use App\Seller360\SyntheticPurger;
use App\Tests\Support\RealElasticsearch;
use Elastic\Elasticsearch\Client;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * `seller360:purge-synthetic` (ETAP 13, Task 11) na ŻYWYM Elasticsearchu: dwa indeksy testowe
 * z mapowaniem szablonów (prywatny i publiczny), dokumenty syntetyczne i prawdziwe.
 */
#[Group('integration')]
final class SyntheticPurgerTest extends TestCase
{
    use RealElasticsearch;

    private const DIR = '/infra/elasticsearch/seller360';

    private Client $client;
    private string $private;
    private string $public;
    private string $sales;
    private string $stream;

    protected function setUp(): void
    {
        $this->client = $this->elasticsearchOrSkip();
        if (! is_dir(self::DIR)) {
            self::markTestSkipped('Brak '.self::DIR.' (montowany w kontenerze search).');
        }
        $suffix = uniqid();
        $this->private = "seller-360-test-{$suffix}";
        $this->public = "seller-360-public-test-{$suffix}";
        // Nazwy spoza `seller-sales-daily` i `events-seller-*`: test nie dotyka żywych danych ani źródła transformu.
        $this->sales = "seller-sales-test-{$suffix}";
        $this->stream = "events-s360purge-test-{$suffix}";
        foreach ([$this->private => 'template-seller-360.json', $this->public => 'template-seller-360-public.json'] as $index => $file) {
            $template = json_decode(file_get_contents(self::DIR.'/'.$file), associative: true, flags: \JSON_THROW_ON_ERROR)['template'];
            $this->client->indices()->create(['index' => $index, 'body' => [
                'settings' => ['number_of_shards' => 1, 'number_of_replicas' => 0, 'analysis' => $template['settings']['analysis']],
                'mappings' => $template['mappings'],
            ]]);
        }
    }

    protected function tearDown(): void
    {
        foreach ([$this->private ?? null, $this->public ?? null, $this->sales ?? null] as $index) {
            if ($index !== null) {
                $this->client->indices()->delete(['index' => $index, 'ignore_unavailable' => true]);
            }
        }
        if (isset($this->stream)) {
            try {
                $this->client->indices()->deleteDataStream(['name' => $this->stream]);
            } catch (\Throwable) {
            }
            try {
                $this->client->indices()->deleteIndexTemplate(['name' => $this->stream]);
            } catch (\Throwable) {
            }
        }
    }

    private function purger(?WriteTargets $targets = null, ?string $main = null, ?string $public = null): SyntheticPurger
    {
        return new SyntheticPurger($this->client, $main ?? $this->private, $public ?? $this->public, $targets, $this->sales, $this->stream);
    }

    public function testDeletesOnlySyntheticDocumentsFromBothIndices(): void
    {
        $body = [];
        foreach ([$this->private, $this->public] as $index) {
            foreach ([1 => false, 5000000 => true, 5000001 => true] as $id => $synthetic) {
                $body[] = ['index' => ['_index' => $index, '_id' => (string) $id]];
                $body[] = ['seller_id' => $id, 'synthetic' => $synthetic];
            }
        }
        $this->client->bulk(['refresh' => true, 'body' => $body]);

        $deleted = $this->purger()->purge();

        self::assertSame([$this->sales => 0, $this->stream => 0, $this->private => 2, $this->public => 2], $deleted, 'brak indeksu sprzedaży i strumienia to 0, nie błąd');
        foreach ([$this->private, $this->public] as $index) {
            $this->client->indices()->refresh(['index' => $index]);
            self::assertSame(1, $this->client->count(['index' => $index])->asArray()['count']);
            self::assertTrue($this->client->exists(['index' => $index, 'id' => '1'])->asBool());
        }
    }

    public function testIteratesOverWriteTargetsInsteadOfConstantAliases(): void
    {
        $body = [];
        foreach ([$this->private, $this->public] as $index) {
            $body[] = ['index' => ['_index' => $index, '_id' => '5000000']];
            $body[] = ['seller_id' => 5000000, 'synthetic' => true];
        }
        $this->client->bulk(['refresh' => true, 'body' => $body]);
        $redis = $this->createStub(\Redis::class);
        $redis->method('get')->willReturn(false);
        $targets = new WriteTargets($redis, new NullLogger, null, 'rt:targets', $this->private, $this->public);

        $deleted = $this->purger($targets, 'seller-360-test-nie-ma-takiego', 'seller-360-test-nie-ma-takiego')->purge();

        self::assertSame([$this->sales => 0, $this->stream => 0, $this->private => 1, $this->public => 1], $deleted);
    }

    /**
     * Bez tego `sales:refresh` (scripted_upsert per seller_id z `seller-sales-daily`) odtworzyłby po purge
     * ~20 tys. dokumentów z samą sekcją `sales` i bez pola `synthetic`. Purge zbiera id syntetycznych PRZED
     * usunięciem dokumentów seller-360 i kasuje ich wiersze sprzedaży oraz zdarzenia w strumieniu źródłowym
     * transformu; wiersze prawdziwego sprzedawcy zostają.
     */
    public function testDeletesSalesRowsAndSellerEventsOfSyntheticSellersOnly(): void
    {
        $body = [];
        foreach ([1 => false, 5000000 => true, 5000001 => true] as $id => $synthetic) {
            $body[] = ['index' => ['_index' => $this->private, '_id' => (string) $id]];
            $body[] = ['seller_id' => (string) $id, 'synthetic' => $synthetic];
        }
        $this->client->bulk(['refresh' => true, 'body' => $body]);

        $this->client->indices()->create(['index' => $this->sales, 'body' => ['settings' => ['number_of_shards' => 1, 'number_of_replicas' => 0], 'mappings' => ['properties' => [
            'seller_id' => ['type' => 'keyword'], 'day' => ['type' => 'date'], 'gmv_cents' => ['type' => 'long'],
        ]]]]);
        $this->client->indices()->putIndexTemplate(['name' => $this->stream, 'body' => [
            'index_patterns' => [$this->stream], 'data_stream' => new \stdClass, 'priority' => 500,
            'template' => ['settings' => ['number_of_shards' => 1, 'number_of_replicas' => 0], 'mappings' => ['properties' => [
                '@timestamp' => ['type' => 'date'], 'seller' => ['properties' => ['id' => ['type' => 'keyword']]], 'kind' => ['type' => 'keyword'],
            ]]],
        ]]);
        $rows = [];
        foreach (['1', '5000000', '5000001'] as $id) {
            foreach (['2026-11-20', '2026-11-21'] as $day) {
                $rows[] = ['index' => ['_index' => $this->sales]];
                $rows[] = ['seller_id' => $id, 'day' => $day, 'gmv_cents' => 100];
                $rows[] = ['create' => ['_index' => $this->stream]];
                $rows[] = ['@timestamp' => "{$day}T10:00:00Z", 'seller' => ['id' => $id], 'kind' => 'order'];
            }
        }
        $response = $this->client->bulk(['refresh' => true, 'body' => $rows])->asArray();
        self::assertFalse($response['errors'], json_encode($response));

        $deleted = $this->purger()->purge();

        self::assertSame([$this->sales => 4, $this->stream => 4, $this->private => 2, $this->public => 0], $deleted);
        foreach ([[$this->sales, 'seller_id'], [$this->stream, 'seller.id']] as [$index, $field]) {
            $this->client->indices()->refresh(['index' => $index]);
            self::assertSame(2, $this->client->count(['index' => $index])->asArray()['count'], $index);
            self::assertSame(2, $this->client->count(['index' => $index, 'body' => ['query' => ['term' => [$field => '1']]]])->asArray()['count'], "{$index}: wiersze prawdziwego sprzedawcy zostają");
        }
    }

    public function testRequestUsesConflictsProceedAndSyntheticTerm(): void
    {
        self::assertSame(
            ['conflicts' => 'proceed', 'refresh' => true, 'body' => ['query' => ['term' => ['synthetic' => true]]]],
            SyntheticPurger::request(),
        );
    }
}
