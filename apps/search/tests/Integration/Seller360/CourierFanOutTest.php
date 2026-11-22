<?php

namespace App\Tests\Integration\Seller360;

use App\Seller360\Registry\CourierFanOut;
use App\Seller360\Registry\RegistryClient;
use App\Tests\Support\RealElasticsearch;
use Elastic\Elasticsearch\Client;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fan-out ocen kuriera (ETAP 13b) na ŻYWYM Elasticsearchu, z prawdziwym skryptem `seller360-courier-rating`
 * i indeksem `courier-ratings` (rola search_service ma tylko tę jedną nazwę, więc test pisze dokumenty
 * o unikalnych `courier_id` i sprząta je po sobie). Dokumenty seller-360 leżą w indeksie `seller-360-test-<uniqid>`.
 * Rejestr to MockHttpClient. Test pomija się, gdy brak indeksu; skrypt musi leżeć w klastrze (rola nie czyta skryptów, brak = błąd 400 przy fan-oucie) (`make seller360-setup`).
 */
#[Group('integration')]
final class CourierFanOutTest extends TestCase
{
    use RealElasticsearch;

    private const TEMPLATE = '/infra/elasticsearch/seller360/template-seller-360.json';

    private Client $client;
    private string $index;
    private string $courier;
    private string $other;

    /** @var list<array<string, mixed>> */
    private array $ratings = [];

    protected function setUp(): void
    {
        $this->client = $this->elasticsearchOrSkip();
        if (! is_file(self::TEMPLATE)) {
            self::markTestSkipped('Brak '.self::TEMPLATE.' (montowany w kontenerze search-consumer).');
        }
        try {
            $this->client->indices()->get(['index' => 'courier-ratings']);
        } catch (\Throwable $e) {
            self::markTestSkipped('Brak indeksu courier-ratings (make seller360-setup): '.$e->getMessage());
        }

        $template = json_decode(file_get_contents(self::TEMPLATE), associative: true, flags: \JSON_THROW_ON_ERROR)['template'];
        $this->index = 'seller-360-test-'.uniqid();
        $this->client->indices()->create(['index' => $this->index, 'body' => [
            'settings' => ['number_of_shards' => 1, 'number_of_replicas' => 0, 'analysis' => $template['settings']['analysis']],
            'mappings' => $template['mappings'],
        ]]);

        $this->courier = 'courier-test-'.uniqid();
        $this->other = 'courier-other-'.uniqid();
        $bulk = [];
        for ($id = 1; $id <= 30; $id++) {
            $courier = $id <= 10 ? $this->courier : $this->other;
            $bulk[] = ['index' => ['_index' => $this->index, '_id' => (string) $id]];
            $bulk[] = ['seller_id' => (string) $id, 'registry' => [
                'legal_name' => "Firma {$id}",
                'courier' => ['id' => $courier, 'on_time_rate' => 0.5, 'rated_at' => '2026-01-01T00:00:00Z'],
                '_meta' => ['version' => 1, 'source_ts' => '2026-11-21T10:00:00Z', 'updated_at' => '2026-11-21T10:00:00Z', 'status' => 'ok'],
            ]];
        }
        $this->client->bulk(['body' => $bulk, 'refresh' => 'true']);
    }

    protected function tearDown(): void
    {
        if (isset($this->index)) {
            $this->client->indices()->delete(['index' => $this->index, 'ignore_unavailable' => true]);
        }
        foreach ([$this->courier ?? null, $this->other ?? null] as $id) {
            if ($id !== null) {
                try {
                    $this->client->delete(['index' => 'courier-ratings', 'id' => $id]);
                } catch (\Throwable) {
                    // kurier bez zapisanej oceny (np. test przerwany przed pollem)
                }
            }
        }
    }

    public function test_zmiana_oceny_aktualizuje_tylko_dokumenty_tego_kuriera(): void
    {
        $this->ratings = [['courier_id' => $this->courier, 'on_time_rate' => 0.91, 'rated_at' => '2026-11-22T08:00:00Z']];

        $result = $this->fanOut()->poll(new \DateTimeImmutable('2026-11-22T00:00:00Z'));

        self::assertSame(1, $result->couriers);
        self::assertSame(10, $result->updated);
        self::assertSame(0, $result->noops);
        self::assertSame(0, $result->conflicts);
        self::assertGreaterThanOrEqual(0, $result->tookMs);

        $this->client->indices()->refresh(['index' => $this->index]);
        $courier = $this->source('3')['registry']['courier'];
        self::assertEqualsWithDelta(0.91, $courier['on_time_rate'], 0.0001);
        self::assertSame('2026-11-22T08:00:00Z', $courier['rated_at']);
        self::assertSame(1, $this->source('3')['registry']['_meta']['version'], 'fan-out nie rusza _meta');
        self::assertEqualsWithDelta(0.5, $this->source('20')['registry']['courier']['on_time_rate'], 0.0001);

        $rating = $this->client->get(['index' => 'courier-ratings', 'id' => $this->courier])->asArray()['_source'];
        self::assertEqualsWithDelta(0.91, $rating['on_time_rate'], 0.0001);
    }

    public function test_ponowny_poll_z_ta_sama_ocena_daje_noopy(): void
    {
        $this->ratings = [['courier_id' => $this->courier, 'on_time_rate' => 0.91, 'rated_at' => '2026-11-22T08:00:00Z']];
        $this->fanOut()->poll(new \DateTimeImmutable('2026-11-22T00:00:00Z'));

        $second = $this->fanOut()->poll(new \DateTimeImmutable('2026-11-22T00:00:00Z'));

        self::assertSame(0, $second->updated);
        self::assertSame(10, $second->noops);
    }

    public function test_starsza_ocena_nie_nadpisuje_nowszej(): void
    {
        $this->ratings = [['courier_id' => $this->courier, 'on_time_rate' => 0.91, 'rated_at' => '2026-11-22T08:00:00Z']];
        $this->fanOut()->poll(new \DateTimeImmutable('2026-11-22T00:00:00Z'));

        $this->ratings = [['courier_id' => $this->courier, 'on_time_rate' => 0.2, 'rated_at' => '2026-11-22T07:00:00+00:00']];
        $stale = $this->fanOut()->poll(new \DateTimeImmutable('2026-11-22T00:00:00Z'));

        self::assertSame(0, $stale->updated);
        self::assertSame(10, $stale->noops);
        $this->client->indices()->refresh(['index' => $this->index]);
        self::assertEqualsWithDelta(0.91, $this->source('1')['registry']['courier']['on_time_rate'], 0.0001);
    }

    public function test_konflikt_jest_ponawiany_i_sumuje_wyniki_prob(): void
    {
        $this->ratings = [['courier_id' => $this->courier, 'on_time_rate' => 0.91, 'rated_at' => '2026-11-22T08:00:00Z']];
        // pierwsza próba "traci" 2 dokumenty przez konflikt wersji, druga (prawdziwa) je domyka
        $fanOut = $this->fanOut(conflictsOnAttempts: [1 => 2]);

        $result = $fanOut->poll(new \DateTimeImmutable('2026-11-22T00:00:00Z'));

        self::assertSame(0, $result->conflicts, 'po udanym ponowieniu brak resztkowych konfliktów');
        self::assertSame(10, $result->updated);
        self::assertSame(10, $result->noops, 'druga próba widzi już zaktualizowane dokumenty jako noopy');
        self::assertSame(2, $fanOut->attempts);
    }

    public function test_resztkowe_konflikty_po_trzech_probach_trafiaja_do_wyniku(): void
    {
        $this->ratings = [['courier_id' => $this->courier, 'on_time_rate' => 0.91, 'rated_at' => '2026-11-22T08:00:00Z']];
        $fanOut = $this->fanOut(conflictsOnAttempts: [1 => 3, 2 => 2, 3 => 1]);

        $result = $fanOut->poll(new \DateTimeImmutable('2026-11-22T00:00:00Z'));

        self::assertSame(1, $result->conflicts);
        self::assertSame(3, $fanOut->attempts);
    }

    public function test_since_przesuwa_sie_tylko_po_czystym_przebiegu(): void
    {
        $redis = $this->memoryRedis();
        $this->ratings = [['courier_id' => $this->courier, 'on_time_rate' => 0.91, 'rated_at' => '2026-11-22T08:00:00Z']];

        $this->fanOut(redis: $redis)->pollFromRedis();

        self::assertSame('2026-11-22T08:00:00Z', $redis->store[CourierFanOut::SINCE_KEY], 'czysty przebieg przesuwa since');

        $this->ratings = [['courier_id' => $this->courier, 'on_time_rate' => 0.95, 'rated_at' => '2026-11-22T09:00:00Z']];
        $dirty = $this->fanOut(redis: $redis, conflictsOnAttempts: [1 => 1, 2 => 1, 3 => 1])->pollFromRedis();

        self::assertSame(1, $dirty->conflicts);
        self::assertSame('2026-11-22T08:00:00Z', $redis->store[CourierFanOut::SINCE_KEY], 'resztkowe konflikty nie przesuwają since');
    }

    public function test_since_nie_przesuwa_sie_po_wyjatku(): void
    {
        $redis = $this->memoryRedis();
        $redis->store[CourierFanOut::SINCE_KEY] = '2026-11-22T08:00:00Z';
        $this->ratings = [['courier_id' => $this->courier, 'on_time_rate' => 0.95, 'rated_at' => '2026-11-22T09:00:00Z']];

        try {
            $this->fanOut(redis: $redis, targets: ['seller-360-test-nie-ma-takiego'])->pollFromRedis();
            self::fail('Oczekiwano wyjątku Elasticsearcha.');
        } catch (\Throwable) {
            self::assertSame('2026-11-22T08:00:00Z', $redis->store[CourierFanOut::SINCE_KEY]);
        }
    }

    public function test_since_do_rejestru_jest_cofniete_o_sekunde_bo_oceny_z_tej_samej_sekundy_nie_moga_zginac(): void
    {
        $redis = $this->memoryRedis();
        $redis->store[CourierFanOut::SINCE_KEY] = '2026-11-22T08:00:00Z';
        $queries = [];
        $http = new MockHttpClient(function (string $method, string $url) use (&$queries): MockResponse {
            $queries[] = $url;

            return new MockResponse('[]', ['response_headers' => ['content-type: application/json']]);
        });
        $fanOut = new CourierFanOut(new RegistryClient('http://registry.invalid', $http, static function (int $ms): void {}), $this->client, $redis, new NullLogger, targets: [$this->index]);

        $fanOut->pollFromRedis();

        self::assertStringContainsString('since=2026-11-22T07:59:59Z', $queries[0]);
    }

    private function memoryRedis(): object
    {
        return new class extends \Redis {
            /** @var array<string, string> */
            public array $store = [];

            public function __construct() {}

            public function get(string $key): mixed
            {
                return $this->store[$key] ?? false;
            }

            public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool
            {
                $this->store[$key] = $value;

                return true;
            }
        };
    }

    /**
     * @param  array<int, int>  $conflictsOnAttempts  numer próby (od 1) => liczba konfliktów dopisana do odpowiedzi zadania
     * @param  list<string>|null  $targets
     */
    private function fanOut(?\Redis $redis = null, array $conflictsOnAttempts = [], ?array $targets = null): CourierFanOut
    {
        $http = new MockHttpClient(fn (): MockResponse => new MockResponse(json_encode($this->ratings), ['response_headers' => ['content-type: application/json']]));

        return new class(new RegistryClient('http://registry.invalid', $http, static function (int $ms): void {}), $this->client, $redis ?? $this->memoryRedis(), new NullLogger, $conflictsOnAttempts, $targets ?? [$this->index]) extends CourierFanOut {
            public int $attempts = 0;

            /** @param array<int, int> $conflictsOnAttempts */
            public function __construct(RegistryClient $registry, Client $es, \Redis $redis, NullLogger $logger, private array $conflictsOnAttempts, array $targets)
            {
                parent::__construct($registry, $es, $redis, $logger, static function (int $ms): void {}, targets: $targets);
            }

            protected function await(string $taskId): array
            {
                $response = parent::await($taskId);
                $this->attempts++;
                $response['version_conflicts'] = ($response['version_conflicts'] ?? 0) + ($this->conflictsOnAttempts[$this->attempts] ?? 0);

                return $response;
            }
        };
    }

    /** @return array<string, mixed> */
    private function source(string $id): array
    {
        return $this->client->get(['index' => $this->index, 'id' => $id])->asArray()['_source'];
    }
}
