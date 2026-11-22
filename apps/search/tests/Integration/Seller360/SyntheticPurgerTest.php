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
    }

    protected function tearDown(): void
    {
        foreach ([$this->private ?? null, $this->public ?? null] as $index) {
            if ($index !== null) {
                $this->client->indices()->delete(['index' => $index, 'ignore_unavailable' => true]);
            }
        }
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

        $deleted = (new SyntheticPurger($this->client, $this->private, $this->public))->purge();

        self::assertSame([$this->private => 2, $this->public => 2], $deleted);
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

        $deleted = (new SyntheticPurger($this->client, 'seller-360-test-nie-ma-takiego', 'seller-360-test-nie-ma-takiego', $targets))->purge();

        self::assertSame([$this->private => 1, $this->public => 1], $deleted);
    }

    public function testRequestUsesConflictsProceedAndSyntheticTerm(): void
    {
        self::assertSame(
            ['conflicts' => 'proceed', 'refresh' => true, 'body' => ['query' => ['term' => ['synthetic' => true]]]],
            SyntheticPurger::request(),
        );
    }
}
