<?php

namespace App\Tests\Unit\Seller360\Registry;

use App\Seller360\Registry\CircuitBreaker;
use App\Seller360\Registry\RegistryClient;
use App\Seller360\Registry\RegistrySync;
use App\Seller360\Registry\RegistryUnavailable;
use App\Seller360\SectionMeta;
use App\Seller360\SectionWriter;
use App\Tests\Support\FakeRedisStore;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RegistrySyncTest extends TestCase
{
    private FakeRedisStore $store;

    /** @var list<array{sellerId: int, section: string, data: array<string, mixed>, meta: SectionMeta, docFields: array<string, mixed>}> */
    private array $writes = [];

    private float $now = 1_000.0;

    protected function setUp(): void
    {
        $this->store = new FakeRedisStore;
        $this->writes = [];
    }

    private function sync(RegistryClient $client, ?Client $es = null): RegistrySync
    {
        $redis = $this->store->redis($this);
        $writer = $this->createStub(SectionWriter::class);
        $writer->method('apply')->willReturnCallback(function (int $id, string $section, array $data, SectionMeta $meta, array $doc = []): string {
            $this->writes[] = ['sellerId' => $id, 'section' => $section, 'data' => $data, 'meta' => $meta, 'docFields' => $doc];

            return 'updated';
        });

        return new RegistrySync($client, new CircuitBreaker($redis, fn (): float => $this->now), $writer, $redis, $es ?? $this->esWithoutRatings(), new NullLogger, fn (): float => $this->now);
    }

    private function esWithoutRatings(): Client
    {
        return $this->es(404, ['error' => 'index_not_found_exception']);
    }

    /** Prawdziwy klient ES z podstawioną odpowiedzią HTTP (Client jest final). */
    private function es(int $status, array $body): Client
    {
        $http = new class($status, json_encode($body)) implements ClientInterface {
            public function __construct(private readonly int $status, private readonly string $body)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response($this->status, ['X-Elastic-Product' => 'Elasticsearch', 'Content-Type' => 'application/json'], $this->body);
            }
        };

        return ClientBuilder::create()->setHosts(['http://es.invalid:9200'])->setHttpClient($http)->build();
    }

    private function healthyClient(): RegistryClient
    {
        $client = $this->createStub(RegistryClient::class);
        $client->method('company')->willReturn(['nip' => '5260250274', 'legal_name' => 'Acme Sp. z o.o.', 'vat_status' => 'active', 'registered_at' => '2015-03-02']);
        $client->method('courierAssignment')->willReturn('courier-3');

        return $client;
    }

    private function downClient(): RegistryClient
    {
        $client = $this->createStub(RegistryClient::class);
        $client->method('company')->willThrowException(new RegistryUnavailable('503'));
        $client->method('courierAssignment')->willThrowException(new RegistryUnavailable('503'));

        return $client;
    }

    public function test_sukces_zapisuje_sekcje_ok_i_cache_na_24h(): void
    {
        $this->sync($this->healthyClient())->syncSeller(7, '5260250274');

        self::assertCount(1, $this->writes);
        $write = $this->writes[0];
        self::assertSame('registry', $write['section']);
        self::assertSame('ok', $write['meta']->status);
        self::assertSame(1_000_000, $write['meta']->version);
        self::assertSame('Acme Sp. z o.o.', $write['data']['legal_name']);
        self::assertSame('active', $write['data']['vat_status']);
        self::assertSame('2015-03-02', $write['data']['registered_at']);
        self::assertSame(['id' => 'courier-3', 'on_time_rate' => null, 'rated_at' => null], $write['data']['courier']);
        self::assertSame(['EX' => 86400], $this->store->setOptions['seller360:registry:5260250274']);
    }

    public function test_ocena_kuriera_z_indeksu_courier_ratings(): void
    {
        $es = $this->es(200, ['_source' => ['courier_id' => 'courier-3', 'on_time_rate' => 0.93, 'rated_at' => '2026-11-21T10:00:00Z']]);

        $this->sync($this->healthyClient(), $es)->syncSeller(7, '5260250274');

        self::assertSame(['id' => 'courier-3', 'on_time_rate' => 0.93, 'rated_at' => '2026-11-21T10:00:00Z'], $this->writes[0]['data']['courier']);
    }

    public function test_outage_keeps_cached_section_and_marks_stale(): void
    {
        $sync = $this->sync($this->healthyClient());
        $sync->syncSeller(7, '5260250274');
        $cached = $this->writes[0]['data'];

        $this->now += 5.0;
        $this->sync($this->downClient())->syncSeller(7, '5260250274');

        self::assertCount(2, $this->writes);
        self::assertSame($cached, $this->writes[1]['data']);
        self::assertSame('stale', $this->writes[1]['meta']->status);
        self::assertNotEmpty($this->writes[1]['meta']->error);
        self::assertGreaterThan($this->writes[0]['meta']->version, $this->writes[1]['meta']->version);
    }

    /**
     * Lag sekcji to `updated_at − source_ts` (ruling R6): zapis `stale` z cache musi nieść czas
     * ostatniego udanego pobrania, inaczej lag rejestru w trakcie awarii stałby na zerze.
     */
    public function test_stale_z_cache_zachowuje_source_ts_ostatniego_udanego_pobrania(): void
    {
        $this->now = 1_700_000_000.0;
        $this->sync($this->healthyClient())->syncSeller(7, '5260250274');

        $this->now += 120.0;
        $this->sync($this->downClient())->syncSeller(7, '5260250274');

        self::assertSame('stale', $this->writes[1]['meta']->status);
        self::assertEquals($this->writes[0]['meta']->sourceTs, $this->writes[1]['meta']->sourceTs);
        self::assertGreaterThan($this->writes[0]['meta']->version, $this->writes[1]['meta']->version);
    }

    public function test_stary_format_cache_bez_czasu_pobrania_dalej_daje_stale(): void
    {
        $this->store->data['seller360:registry:5260250274'] = json_encode(['legal_name' => 'Acme', 'vat_status' => 'active', 'registered_at' => null, 'courier' => ['id' => 'courier-1', 'on_time_rate' => null, 'rated_at' => null]]);

        $this->sync($this->downClient())->syncSeller(7, '5260250274');

        self::assertSame('stale', $this->writes[0]['meta']->status);
        self::assertSame('Acme', $this->writes[0]['data']['legal_name']);
    }

    public function test_porazka_bez_cache_zapisuje_tylko_meta_ze_statusem_error(): void
    {
        $this->sync($this->downClient())->syncSeller(7, '5260250274');

        self::assertSame([], $this->writes[0]['data']);
        self::assertSame('error', $this->writes[0]['meta']->status);
        self::assertNotEmpty($this->writes[0]['meta']->error);
    }

    public function test_otwarty_breaker_nie_wola_klienta_ale_dalej_zapisuje_stale_z_cache(): void
    {
        $this->sync($this->healthyClient())->syncSeller(7, '5260250274');

        $down = $this->createStub(RegistryClient::class);
        $down->method('company')->willThrowException(new RegistryUnavailable('503'));
        $sync = $this->sync($down);
        for ($i = 0; $i < 5; $i++) {
            $sync->syncSeller(7, '5260250274');
        }

        $never = $this->createMock(RegistryClient::class);
        $never->expects(self::never())->method('company');
        $this->sync($never)->syncSeller(7, '5260250274');

        self::assertSame('stale', $this->writes[array_key_last($this->writes)]['meta']->status);
        self::assertCount(7, $this->writes);
    }

    public function test_sukces_resetuje_breaker(): void
    {
        $sync = $this->sync($this->downClient());
        for ($i = 0; $i < 4; $i++) {
            $sync->syncSeller(7, '5260250274');
        }
        $this->sync($this->healthyClient())->syncSeller(7, '5260250274');
        $this->sync($this->downClient())->syncSeller(7, '5260250274');

        self::assertFalse((new CircuitBreaker($this->store->redis($this), fn (): float => $this->now))->isOpen());
    }
}
