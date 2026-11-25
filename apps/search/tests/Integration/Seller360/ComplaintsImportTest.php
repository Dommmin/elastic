<?php

namespace App\Tests\Integration\Seller360;

use App\Seller360\Complaints\ComplaintsImporter;
use App\Seller360\Complaints\ComplaintsSectionRefresher;
use App\Seller360\SectionWriter;
use App\Seller360\Seller360Metrics;
use App\Tests\Support\RealElasticsearch;
use Elastic\Elasticsearch\Client;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Import reklamacji (ETAP 13) na ŻYWYM klastrze: polityka enrich `seller-by-nip` czyta alias `seller-360`,
 * więc test dopisuje tam 3 dokumenty testowe (seller_id >= 990000001, unikalne NIP-y), a w tearDown
 * usuwa je razem z wierszami reklamacji (po ticket_id z prefiksem T9TEST-) i ponownie wykonuje politykę.
 * Wymaga `make seller360-setup` (szablony, pipeline, polityka).
 */
#[Group('integration')]
final class ComplaintsImportTest extends TestCase
{
    use RealElasticsearch;

    private const FIXTURE = __DIR__.'/../../Fixtures/complaints-sample.csv';
    private const SELLERS = [990000001 => '9990000001', 990000002 => '9990000002'];
    private const LATE_SELLER = 990000003;
    private const LATE_NIP = '9990000003';
    private const POLICY = 'seller-by-nip';

    private Client $client;
    private ComplaintsImporter $importer;
    /** @var list<string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        $this->client = $this->elasticsearchOrSkip();
        if (! $this->client->indices()->exists(['index' => 'complaints'])->asBool()) {
            self::markTestSkipped('Brak indeksu complaints — uruchom `make seller360-setup`.');
        }
        $this->cleanup();
        foreach (self::SELLERS as $id => $nip) {
            $this->indexSeller($id, $nip);
        }

        $writer = new SectionWriter($this->client, new Seller360Metrics($this->createStub(\Redis::class), new NullLogger), refresh: true);
        $this->importer = new ComplaintsImporter($this->client, new ComplaintsSectionRefresher($this->client, $writer, new NullLogger), new NullLogger);
    }

    protected function tearDown(): void
    {
        if (isset($this->client)) {
            $this->cleanup();
        }
        foreach ($this->tmpFiles as $file) {
            @unlink($file);
        }
    }

    public function testUnknownNipGoesToUnmatched(): void
    {
        $result = $this->importer->import(self::FIXTURE);

        self::assertSame(3, $result->indexed);
        self::assertSame(1, $result->unmatched);
        self::assertSame(0, $result->failed);
        self::assertSame(3, $this->countTickets('complaints'));
        self::assertSame(1, $this->countTickets('complaints-unmatched'));

        $row = $this->client->get(['index' => 'complaints', 'id' => 'T9TEST-001'])->asArray()['_source'];
        self::assertSame(990000001, $row['seller_id']);
        self::assertArrayNotHasKey('seller', $row, 'pole pomocnicze enrich jest usuwane');
        self::assertSame("Przesyłka szła dwa tygodnie, \"express\" to żart,\na obsługa nie odpowiada.", $this->client->get(['index' => 'complaints', 'id' => 'T9TEST-002'])->asArray()['_source']['body'], 'cudzysłowy i nowa linia w polu CSV (RFC 4180)');
        self::assertSame('9990000003', $this->client->get(['index' => 'complaints-unmatched', 'id' => 'T9TEST-004'])->asArray()['_source']['nip']);
    }

    public function testReimportDoesNotGrowDocumentCounts(): void
    {
        $this->importer->import(self::FIXTURE);
        $second = $this->importer->import(self::FIXTURE);

        self::assertSame(3, $second->indexed);
        self::assertSame(1, $second->unmatched);
        self::assertSame(3, $this->countTickets('complaints'));
        self::assertSame(1, $this->countTickets('complaints-unmatched'));
    }

    public function testComplaintsSectionIsFullTextSearchableOnSeller360(): void
    {
        $this->importer->import(self::FIXTURE);

        $hits = $this->client->search(['index' => 'seller-360', 'body' => [
            'query' => ['bool' => [
                'must' => [['simple_query_string' => ['query' => 'uszkodzon*', 'fields' => ['complaints.recent_texts']]]],
                'filter' => [['range' => ['seller_id' => ['gte' => 990000000]]]],
            ]],
        ]])->asArray()['hits']['hits'];

        self::assertCount(1, $hits);
        self::assertSame('990000001', $hits[0]['_id']);
        $section = $hits[0]['_source']['complaints'];
        self::assertSame(1, $section['open'], 'T9TEST-001 open, T9TEST-002 closed');
        self::assertEqualsCanonicalizing(['uszkodzenie', 'opóźnienie'], $section['top_categories']);
        self::assertCount(2, $section['recent_texts']);
        self::assertSame('ok', $section['_meta']['status']);

        $other = $this->client->get(['index' => 'seller-360', 'id' => '990000002'])->asArray()['_source']['complaints'];
        self::assertSame(1, $other['open'], 'in_progress liczy się jako otwarta');
    }

    public function testRetryUnmatchedMovesRowAfterSellerAppears(): void
    {
        $this->importer->import(self::FIXTURE);
        self::assertSame(1, $this->countTickets('complaints-unmatched'));

        $stillMissing = $this->importer->retryUnmatched();
        self::assertSame(0, $stillMissing->indexed);
        self::assertSame(1, $stillMissing->unmatched);
        self::assertSame(1, $this->countTickets('complaints-unmatched'));

        $this->indexSeller(self::LATE_SELLER, self::LATE_NIP);
        $result = $this->importer->retryUnmatched();

        self::assertSame(1, $result->indexed);
        self::assertSame(0, $result->unmatched);
        self::assertSame(0, $this->countTickets('complaints-unmatched'));
        self::assertSame(4, $this->countTickets('complaints'));
        self::assertSame(990000003, $this->client->get(['index' => 'complaints', 'id' => 'T9TEST-004'])->asArray()['_source']['seller_id']);
        $section = $this->client->get(['index' => 'seller-360', 'id' => (string) self::LATE_SELLER])->asArray()['_source']['complaints'];
        self::assertSame(1, $section['open'], 'status new liczy się jako otwarta');
    }

    public function testSectionCountsLast30DaysAndKeepsOnly50NewestTexts(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $rows = [];
        for ($i = 0; $i < 55; $i++) {
            $rows[] = ['T9TEST-B'.str_pad((string) $i, 3, '0', \STR_PAD_LEFT), '9990000001', $now->modify("-{$i} days")->format('Y-m-d\TH:i:s\Z'), 'jakość', 'closed', "Tekst numer {$i}"];
        }
        $result = $this->importer->import($this->csv($rows));

        self::assertSame(55, $result->indexed);
        $section = $this->client->get(['index' => 'seller-360', 'id' => '990000001'])->asArray()['_source']['complaints'];
        self::assertSame(30, $section['last_30d'], 'dni 0..29 wstecz; dzień 30 jest już starszy niż now-30d z chwili przeliczenia');
        self::assertCount(50, $section['recent_texts']);
        self::assertSame('Tekst numer 0', $section['recent_texts'][0]);
        self::assertSame(['jakość'], $section['top_categories']);
        self::assertSame(0, $section['open']);
    }

    public function testZeroMissingClearsSectionOfSellerWithoutComplaintsLeft(): void
    {
        // ETAP 15: `security:purge-synthetic` usuwa reklamacje SEC-; sprzedawca bez żadnej reklamacji nie może
        // zostać ze starą sekcją (agregacja go nie zwraca, więc bez `zeroMissing` nic by jej nie nadpisało).
        $this->importer->import(self::FIXTURE);
        $this->client->deleteByQuery(['index' => 'complaints', 'refresh' => true, 'body' => ['query' => ['terms' => ['ticket_id' => ['T9TEST-001', 'T9TEST-002']]]]]);
        $writer = new SectionWriter($this->client, new Seller360Metrics($this->createStub(\Redis::class), new NullLogger), refresh: true);
        $refresher = new ComplaintsSectionRefresher($this->client, $writer, new NullLogger);

        self::assertSame(2, $refresher->refreshSellers([990000001, 990000002], zeroMissing: true));

        $empty = $this->client->get(['index' => 'seller-360', 'id' => '990000001'])->asArray()['_source']['complaints'];
        self::assertSame(0, $empty['open']);
        self::assertSame(0, $empty['last_30d']);
        self::assertSame([], $empty['top_categories']);
        self::assertSame([], $empty['recent_texts']);
        self::assertSame(1, $this->client->get(['index' => 'seller-360', 'id' => '990000002'])->asArray()['_source']['complaints']['open'], 'sprzedawca z reklamacjami bez zmian');
    }

    public function testWrongHeaderIsRejected(): void
    {
        $path = $this->csv([], "id,nip,date\n");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nagłówek');
        $this->importer->import($path);
    }

    /** @param list<list<string>> $rows */
    private function csv(array $rows, ?string $rawHeader = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'complaints');
        $this->tmpFiles[] = $path;
        $handle = fopen($path, 'w');
        if ($rawHeader !== null) {
            fwrite($handle, $rawHeader);
        } else {
            fputcsv($handle, ['ticket_id', 'nip', 'created_at', 'category', 'status', 'body'], escape: '');
        }
        foreach ($rows as $row) {
            fputcsv($handle, $row, escape: '');
        }
        fclose($handle);

        return $path;
    }

    private function indexSeller(int $id, string $nip): void
    {
        $this->client->index(['index' => 'seller-360-write', 'id' => (string) $id, 'refresh' => 'true', 'body' => [
            'seller_id' => (string) $id, 'nip' => $nip, 'region' => 'łódzkie', 'synthetic' => true,
        ]]);
    }

    private function countTickets(string $index): int
    {
        $this->client->indices()->refresh(['index' => $index]);

        return (int) $this->client->count(['index' => $index, 'body' => ['query' => ['prefix' => ['ticket_id' => 'T9TEST-']]]])->asArray()['count'];
    }

    /** Usuwa wszystko, co test mógł zostawić: dokumenty sprzedawców, wiersze reklamacji i ślad w indeksie enrich. */
    private function cleanup(): void
    {
        $ids = array_map('strval', [...array_keys(self::SELLERS), self::LATE_SELLER]);
        $this->client->deleteByQuery(['index' => 'seller-360-write,seller-360-public-write', 'refresh' => true, 'conflicts' => 'proceed', 'body' => ['query' => ['ids' => ['values' => $ids]]]]);
        $this->client->deleteByQuery(['index' => 'complaints,complaints-unmatched', 'refresh' => true, 'conflicts' => 'proceed', 'body' => ['query' => ['prefix' => ['ticket_id' => 'T9TEST-']]]]);
        $this->client->enrich()->executePolicy(['name' => self::POLICY, 'wait_for_completion' => true]);
    }
}
