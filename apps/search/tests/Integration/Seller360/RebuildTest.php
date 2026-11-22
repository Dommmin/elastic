<?php

namespace App\Tests\Integration\Seller360;

use App\Seller360\CatalogSellerClient;
use App\Seller360\Complaints\ComplaintsSectionRefresher;
use App\Seller360\OffersSectionFlusher;
use App\Seller360\Rebuild\RebuildNames;
use App\Seller360\Rebuild\Seller360Rebuilder;
use App\Seller360\Rebuild\WriteTargets;
use App\Seller360\RegionResolver;
use App\Seller360\SalesSectionRefresher;
use App\Seller360\SectionMeta;
use App\Seller360\SectionWriter;
use App\Seller360\Seller360Metrics;
use App\Tests\Support\RealElasticsearch;
use Elastic\Elasticsearch\Client;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Przebudowa seller-360 bez przestoju (ETAP 13, Task 14) na ŻYWYM Elasticsearchu, w osobnej przestrzeni nazw
 * (`seller-360-rt<id>`, `seller-360-public-rt<id>`): żywe aliasy nie są ruszane. Szablony `seller-360`
 * i `seller-360-public` oraz skrypt `seller360-apply-section` muszą leżeć w klastrze (`make seller360-setup`).
 * Catalog to dubler z ustalonymi snapshotami, Redis — tablica w pamięci.
 */
#[Group('integration')]
final class RebuildTest extends TestCase
{
    use RealElasticsearch;

    private const DIR = '/infra/elasticsearch/seller360';
    private const CSV = '/infra/reference/miasta-wojewodztwa.csv';
    private const NIP_7 = '1234563218';
    private const NIP_8 = '5260250274';

    private Client $client;
    private string $ns;
    private RebuildNames $names;
    private WriteTargets $targets;
    private SectionWriter $writer;
    private \Redis $redis;
    private string $sales;
    private string $complaints;

    /** @var array<string, string> */
    public array $store = [];

    protected function setUp(): void
    {
        $this->client = $this->elasticsearchOrSkip();
        if (! is_dir(self::DIR) || ! is_file(self::CSV)) {
            self::markTestSkipped('Brak '.self::DIR.' albo '.self::CSV.' (montowane w kontenerze search).');
        }
        $this->ns = 'rt'.uniqid();
        $this->names = new RebuildNames("seller-360-{$this->ns}", "seller-360-public-{$this->ns}", ratingsIndex: 'courier-ratings', enrichPolicy: null);
        foreach ([$this->names->mainAlias => 'template-seller-360.json', $this->names->publicAlias => 'template-seller-360-public.json'] as $alias => $file) {
            $template = json_decode(file_get_contents(self::DIR.'/'.$file), associative: true, flags: \JSON_THROW_ON_ERROR)['template'];
            $this->client->indices()->create(['index' => "{$alias}-v1", 'body' => [
                'settings' => ['number_of_shards' => 1, 'number_of_replicas' => 0, 'analysis' => $template['settings']['analysis']],
                'mappings' => $template['mappings'],
                'aliases' => [$alias => new \stdClass, "{$alias}-write" => ['is_write_index' => true]],
            ]]);
        }
        $this->sales = "seller-sales-{$this->ns}";
        $this->client->indices()->create(['index' => $this->sales, 'body' => ['settings' => ['number_of_shards' => 1, 'number_of_replicas' => 0], 'mappings' => ['properties' => [
            'seller_id' => ['type' => 'keyword'], 'day' => ['type' => 'date'], 'cart_sessions' => ['type' => 'long'], 'order_sessions' => ['type' => 'long'], 'gmv_cents' => ['type' => 'long'],
        ]]]]);
        $this->complaints = "complaints-{$this->ns}";
        $this->client->indices()->create(['index' => $this->complaints, 'body' => ['settings' => ['number_of_shards' => 1, 'number_of_replicas' => 0], 'mappings' => ['properties' => [
            'seller_id' => ['type' => 'long'], 'status' => ['type' => 'keyword'], 'created_at' => ['type' => 'date'], 'category' => ['type' => 'keyword'], 'body' => ['type' => 'text'],
        ]]]]);
        $this->client->index(['index' => $this->complaints, 'refresh' => 'true', 'body' => [
            'seller_id' => 7, 'status' => 'open', 'created_at' => (new \DateTimeImmutable('-2 days'))->format('Y-m-d\TH:i:s\Z'), 'category' => 'uszkodzenie', 'body' => 'Paczka zgnieciona',
        ]]);

        $this->redis = $this->memoryRedis();
        $this->targets = new WriteTargets($this->redis, new NullLogger, null, "seller360:write-targets:{$this->ns}", $this->names->mainWriteAlias(), $this->names->publicWriteAlias());
        $this->writer = new SectionWriter($this->client, new Seller360Metrics($this->createStub(\Redis::class), new NullLogger), targets: $this->targets);

        // Stan sprzed przebudowy: syntetyczny 7 z profilem, billingiem v5 i rejestrem; prawdziwy 8 bez profilu
        // (brak historycznych zdarzeń seller.*, ruling R5), tylko z ofertami.
        $this->writer->apply(7, 'profile', ['name' => 'Sklep 7', 'city' => 'Łódź', 'status' => 'active', 'status_reason' => null], $this->meta(3), ['nip' => self::NIP_7, 'region' => 'łódzkie', 'synthetic' => true]);
        $this->writer->apply(7, 'billing', ['balance_cents' => 500, 'overdue_cents' => 0, 'overdue_since' => null, 'open_invoices' => 1, 'last_invoice_id' => 'F1'], $this->meta(5));
        $fetchedAt = time() - 600;
        $registry = ['legal_name' => 'Sklep 7 sp. z o.o.', 'vat_status' => 'active', 'registered_at' => '2020-01-01', 'courier' => ['id' => 'courier-rt', 'on_time_rate' => 0.9, 'rated_at' => '2026-11-20T10:00:00Z']];
        $this->writer->apply(7, 'registry', $registry, new SectionMeta($fetchedAt * 1000 + 250, (new \DateTimeImmutable)->setTimestamp($fetchedAt)), ['nip' => self::NIP_7]);
        $this->store['seller360:registry:'.self::NIP_7] = json_encode(['fetched_at' => $fetchedAt, 'data' => $registry]);
        $this->writer->apply(8, 'offers', ['active' => 1, 'suspended' => 0, 'sold_out' => 0, 'total' => 1, 'computed_at' => '2026-11-21T10:00:00.000+00:00'], $this->meta(1_763_719_200_000));
    }

    protected function tearDown(): void
    {
        if (isset($this->ns)) {
            $indices = [$this->sales, $this->complaints];
            foreach ([1, 2] as $v) {
                $indices[] = "seller-360-{$this->ns}-v{$v}";
                $indices[] = "seller-360-public-{$this->ns}-v{$v}";
            }
            $this->client->indices()->delete(['index' => implode(',', $indices), 'ignore_unavailable' => true]);
        }
    }

    public function test_zdarzenie_w_trakcie_backfillu_wygrywa_z_starszym_snapshotem(): void
    {
        $report = $this->rebuilder()->run(afterDualStart: function (): void {
            // billing v6 przychodzi, zanim backfill zapisze snapshot v5 z catalogu
            $this->writer->apply(7, 'billing', ['balance_cents' => 600, 'overdue_cents' => 0, 'overdue_since' => null, 'open_invoices' => 2, 'last_invoice_id' => 'F2'], $this->meta(6));
        });

        self::assertSame('switched', $report->status, implode('; ', $report->mismatches));
        $v2 = "seller-360-{$this->ns}-v2";
        $publicV2 = "seller-360-public-{$this->ns}-v2";
        self::assertSame($v2, $report->newMain);
        self::assertSame([$v2], array_keys($this->client->indices()->getAlias(['name' => $this->names->mainAlias])->asArray()));
        self::assertSame([$publicV2], array_keys($this->client->indices()->getAlias(['name' => $this->names->publicWriteAlias()])->asArray()));

        $seven = $this->source($v2, 7);
        self::assertSame(6, $seven['billing']['_meta']['version'], 'strażnik wersji: snapshot v5 z backfillu to noop wobec v6');
        self::assertSame(600, $seven['billing']['balance_cents']);
        self::assertSame(3, $seven['profile']['_meta']['version']);
        self::assertSame('Sklep 7 sp. z o.o.', $seven['registry']['legal_name']);
        self::assertSame('ok', $seven['registry']['_meta']['status']);
        self::assertSame(1, $seven['complaints']['open']);

        $eight = $this->source($v2, 8);
        self::assertSame('Prawdziwy 8', $eight['profile']['name'], 'prawdziwy sprzedawca dostaje profil z /api/internal/sellers');
        self::assertSame(self::NIP_8, $eight['nip']);
        self::assertSame('małopolskie', $eight['region']);
        self::assertSame(4, $eight['offers']['active'], 'oferty przeliczone na nowo');

        $public = $this->source($publicV2, 7);
        self::assertArrayNotHasKey('billing', $public);
        self::assertSame('Sklep 7', $public['profile']['name']);

        self::assertTrue($this->client->indices()->exists(['index' => "seller-360-{$this->ns}-v1"])->asBool(), 'stare indeksy zostają bez --delete-old');
        self::assertSame([['main' => $this->names->mainWriteAlias(), 'public' => $this->names->publicWriteAlias()]], $this->targets->current());
        self::assertSame(['main' => 2, 'public' => 2], $report->countsNew);
        self::assertSame(2, $report->sampled);
        self::assertArrayHasKey('backfill', $report->phasesMs);

        // po przełączeniu zwykły zapis idzie przez alias do v2
        $this->writer->apply(7, 'profile', ['name' => 'Po przebudowie', 'city' => 'Łódź', 'status' => 'active', 'status_reason' => null], $this->meta(4));
        self::assertSame('Po przebudowie', $this->source($v2, 7)['profile']['name']);
    }

    public function test_rozjazd_wersji_przerywa_przebudowe_bez_przelaczenia_aliasow(): void
    {
        $old = $this->writer->forPairs([['main' => "seller-360-{$this->ns}-v1", 'public' => "seller-360-public-{$this->ns}-v1"]]);

        $report = $this->rebuilder()->run(afterDualStart: function () use ($old): void {
            // zapis z pominięciem podwójnego zapisu: trafia tylko do starego indeksu
            $old->apply(7, 'billing', ['balance_cents' => 900], $this->meta(9));
        });

        self::assertSame('aborted', $report->status);
        self::assertNotEmpty($report->mismatches);
        self::assertStringContainsString('billing', implode(' ', $report->mismatches));
        self::assertSame(["seller-360-{$this->ns}-v1"], array_keys($this->client->indices()->getAlias(['name' => $this->names->mainAlias])->asArray()));
        self::assertTrue($this->client->indices()->exists(['index' => "seller-360-{$this->ns}-v2"])->asBool(), 'nowe indeksy zostają do oględzin');
        self::assertCount(1, $this->targets->current(), 'podwójny zapis zatrzymany');
    }

    /** Błąd `_aliases` w fazie switch też wyłącza podwójny zapis (wcześniej klucz zostawał z dwiema parami, RUNBOOK #147). */
    public function test_nieudane_przelaczenie_aliasow_wylacza_podwojny_zapis(): void
    {
        $rebuilder = $this->rebuilder();
        $failed = false;
        try {
            $rebuilder->run(afterDualStart: function (): void {
                // zwykły indeks o nazwie aliasu odczytu: akcja `add` w `_aliases` dostaje 400 i całe żądanie pada
                $this->client->indices()->deleteAlias(['index' => "seller-360-{$this->ns}-v1", 'name' => $this->names->mainAlias]);
                $this->client->indices()->create(['index' => $this->names->mainAlias, 'body' => ['settings' => ['number_of_shards' => 1, 'number_of_replicas' => 0]]]);
            });
        } catch (\Elastic\Elasticsearch\Exception\ClientResponseException) {
            $failed = true;
        } finally {
            $this->client->indices()->delete(['index' => $this->names->mainAlias, 'ignore_unavailable' => true]);
        }

        self::assertTrue($failed, 'przełączenie aliasów miało paść');

        self::assertCount(1, $this->targets->current(), 'podwójny zapis zatrzymany mimo błędu w fazie switch');
        self::assertSame($this->names->mainWriteAlias(), $this->targets->current()[0]['main']);
    }

    public function test_druga_przebudowa_przy_trwajacym_podwojnym_zapisie_odmawia(): void
    {
        $this->targets->startDual('seller-360-cos', 'seller-360-public-cos');

        $this->expectException(\RuntimeException::class);
        $this->rebuilder()->run();
    }

    public function test_delete_old_usuwa_stare_indeksy_po_przelaczeniu(): void
    {
        $report = $this->rebuilder()->run(deleteOld: true);

        self::assertSame('switched', $report->status, implode('; ', $report->mismatches));
        self::assertFalse($this->client->indices()->exists(['index' => "seller-360-{$this->ns}-v1"])->asBool());
        self::assertFalse($this->client->indices()->exists(['index' => "seller-360-public-{$this->ns}-v1"])->asBool());
    }

    private function rebuilder(): Seller360Rebuilder
    {
        $catalog = new class('http://catalog.invalid') extends CatalogSellerClient {
            public function sellers(int $after, int $limit): array
            {
                return ['items' => $after > 0 ? [] : [
                    ['seller_id' => 7, 'name' => 'Sklep 7', 'city' => 'Łódź', 'nip' => RebuildTest::nip(7), 'status' => 'active', 'status_reason' => null, 'synthetic' => true, 'version' => 3],
                    ['seller_id' => 8, 'name' => 'Prawdziwy 8', 'city' => 'Kraków', 'nip' => RebuildTest::nip(8), 'status' => 'active', 'status_reason' => null, 'synthetic' => false, 'version' => 2],
                ], 'next_after' => null];
            }

            public function billingAccounts(int $after, int $limit): array
            {
                // snapshot odczytany "przed" zdarzeniem v6
                return ['items' => $after > 0 ? [] : [
                    ['seller_id' => 7, 'balance_cents' => 500, 'overdue_cents' => 0, 'overdue_since' => null, 'open_invoices' => 1, 'version' => 5],
                ], 'next_after' => null];
            }

            public function offerStatsMany(array $sellerIds): array
            {
                $stats = [];
                foreach ($sellerIds as $id) {
                    $stats[$id] = ['seller_id' => $id, 'active' => 4, 'suspended' => 0, 'sold_out' => 1, 'total' => 5, 'computed_at' => (new \DateTimeImmutable)->format('Y-m-d\TH:i:s.vP')];
                }

                return $stats;
            }
        };
        $logger = new NullLogger;

        return new Seller360Rebuilder(
            $this->client,
            $this->writer,
            $this->targets,
            $catalog,
            new RegionResolver(self::CSV),
            new OffersSectionFlusher($this->redis, $catalog, $this->writer, $logger),
            new SalesSectionRefresher($this->client, $this->writer, $logger, $this->sales),
            new ComplaintsSectionRefresher($this->client, $this->writer, $logger, $this->complaints),
            $this->redis,
            $logger,
            $this->names,
            sleepMs: static function (int $ms): void {},
            replicas: 0,
        );
    }

    public static function nip(int $sellerId): string
    {
        return $sellerId === 7 ? self::NIP_7 : self::NIP_8;
    }

    private function meta(int $version): SectionMeta
    {
        return new SectionMeta($version, new \DateTimeImmutable('2026-11-21T09:59:00+00:00'));
    }

    /** @return array<string, mixed> */
    private function source(string $index, int $id): array
    {
        return $this->client->get(['index' => $index, 'id' => (string) $id])->asArray()['_source'];
    }

    private function memoryRedis(): \Redis
    {
        $test = $this;

        return new class($test) extends \Redis {
            public function __construct(private RebuildTest $test) {}

            public function get(string $key): mixed
            {
                return $this->test->store[$key] ?? false;
            }

            public function mGet(array $keys): \Redis|array|false
            {
                return array_map(fn (string $k) => $this->test->store[$k] ?? false, $keys);
            }

            public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool
            {
                $this->test->store[$key] = $value;

                return true;
            }

            public function del(array|string $key, string ...$other_keys): \Redis|int|false
            {
                unset($this->test->store[$key]);

                return 1;
            }
        };
    }
}
