<?php

namespace App\Tests\Integration\Security;

use App\Security\AlertWriter;
use App\Security\Detect\ComplaintsSpikeDetector;
use App\Security\SecurityDetectJob;
use App\Tests\Support\RealElasticsearch;
use Elastic\Elasticsearch\Client;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * `complaints-spike` na PRAWDZIWYM Elasticsearchu: izolowany indeks `complaints-test-*` (rola search_service
 * ma `complaints*`) z uproszczonym mapowaniem, tylko pola, które czyta detektor.
 * Dni 0..6 to historia (2026-11-12..18), dzień 7 (2026-11-19) jest oceniany.
 */
#[Group('integration')]
final class ComplaintsSpikeDetectorTest extends TestCase
{
    use RealElasticsearch;

    private const DAY0 = '2026-11-12';
    private const EVAL_DAY = '2026-11-19';

    private Client $client;
    private string $index;
    private string $alertsIndex;
    private int $seq = 0;

    protected function setUp(): void
    {
        $this->client = $this->elasticsearchOrSkip();
        $suffix = uniqid();
        $this->index = 'complaints-test-'.$suffix;
        $this->alertsIndex = 'alerts-security-test-'.$suffix;
        $this->client->indices()->create(['index' => $this->index, 'body' => [
            'settings' => ['index.number_of_replicas' => 0],
            'mappings' => ['properties' => [
                'ticket_id' => ['type' => 'keyword'],
                'seller_id' => ['type' => 'long'],
                'category' => ['type' => 'keyword'],
                'created_at' => ['type' => 'date'],
            ]],
        ]]);
        if (! is_file('/infra/elasticsearch/detection/index-alerts-security.json')) {
            self::markTestSkipped('Brak index-alerts-security.json (mount search-consumer).');
        }
        $alerts = json_decode(file_get_contents('/infra/elasticsearch/detection/index-alerts-security.json'), true, flags: \JSON_THROW_ON_ERROR);
        $alerts['settings']['index.number_of_replicas'] = 0;
        $this->client->indices()->create(['index' => $this->alertsIndex, 'body' => $alerts]);
    }

    protected function tearDown(): void
    {
        foreach ([$this->index, $this->alertsIndex] as $index) {
            $this->client->indices()->delete(['index' => $index, 'ignore_unavailable' => true]);
        }
    }

    private function complaints(int $seller, int $dayIndex, int $count, string $category = 'inne', string $prefix = 'T-'): void
    {
        $day = (new \DateTimeImmutable(self::DAY0))->modify("+{$dayIndex} days");
        for ($i = 0; $i < $count; ++$i) {
            $this->client->index(['index' => $this->index, 'body' => [
                'ticket_id' => $prefix.++$this->seq,
                'seller_id' => $seller,
                'category' => $category,
                'created_at' => $day->modify('+'.(8 + $i % 10).' hours')->format('Y-m-d\TH:i:s\Z'),
            ]]);
        }
    }

    /** @param list<int> $history */
    private function seller(int $seller, array $history, int $spike, string $prefix = 'T-'): void
    {
        foreach ($history as $day => $count) {
            $this->complaints($seller, $day, $count, 'inne', $prefix);
        }
        $this->complaints($seller, 7, $spike, 'opóźniona dostawa', $prefix);
    }

    private function detect(string $from = self::EVAL_DAY.'T00:00:00Z', string $to = '2026-11-20T00:00:00Z'): array
    {
        $this->client->indices()->refresh(['index' => $this->index]);

        return (new ComplaintsSpikeDetector($this->client, $this->index))->detect(new \DateTimeImmutable($from), new \DateTimeImmutable($to));
    }

    public function test_skok_po_spokojnym_tygodniu_daje_alert_z_oknem_doby_i_dowodami(): void
    {
        $this->seller(101, [1, 2, 1, 2, 1, 2, 1], 12);
        $this->complaints(105, 7, 20, 'uszkodzony towar');

        $alerts = $this->detect();

        self::assertCount(1, $alerts);
        $a = $alerts[0];
        self::assertSame('complaints-spike', $a->ruleId);
        self::assertSame('seller', $a->entityType);
        self::assertSame('101', $a->entityId);
        self::assertEquals(new \DateTimeImmutable(self::EVAL_DAY.'T00:00:00Z'), $a->windowStart);
        self::assertEquals(new \DateTimeImmutable('2026-11-20T00:00:00Z'), $a->windowEnd);
        self::assertFalse($a->synthetic);
        self::assertSame(12, $a->evidence['count']);
        self::assertSame(1.43, $a->evidence['mean']);
        self::assertSame('1,2,1,2,1,2,1', $a->evidence['daily_counts']);
        self::assertStringContainsString('opóźniona dostawa', $a->evidence['top_categories']);
        self::assertIsBool($a->evidence['seller_significant']);
    }

    public function test_sprzedawca_z_3_dziennie_i_6_osmego_dnia_nie_dostaje_alertu(): void
    {
        // Historia 2,4,3,5,3,4,2: średnia 3,29 i σ ≈ 1,03 dają próg ≈ 6,4; stałe 3/dzień (σ = 0) dałyby próg 3.
        $this->seller(102, [2, 4, 3, 5, 3, 4, 2], 6);

        self::assertSame([], $this->detect());
    }

    public function test_nowy_sprzedawca_z_6_reklamacjami_jednego_dnia_nie_ma_historii(): void
    {
        $this->complaints(103, 7, 6);

        self::assertSame([], $this->detect());
    }

    public function test_dzien_nie_liczy_sam_siebie_do_sredniej(): void
    {
        $this->seller(106, [1, 0, 0, 0, 0, 0, 1], 8);

        $alerts = $this->detect();

        self::assertCount(1, $alerts);
        self::assertSame(0.29, $alerts[0]->evidence['mean']);
    }

    public function test_alert_jest_syntetyczny_tylko_gdy_wszystkie_zgloszenia_z_symulatora(): void
    {
        $this->seller(104, [1, 2, 1, 2, 1, 2, 1], 12, 'SEC-');
        $this->seller(107, [1, 2, 1, 2, 1, 2, 1], 11);
        $this->complaints(107, 7, 1, 'inne', 'SEC-');

        $bySeller = [];
        foreach ($this->detect() as $a) {
            $bySeller[$a->entityId] = $a->synthetic;
        }

        self::assertSame(['104' => true, '107' => false], $bySeller);
    }

    public function test_okno_dwugodzinne_w_biezacej_dobie_ocenia_ta_sama_dobe(): void
    {
        $this->seller(101, [1, 2, 1, 2, 1, 2, 1], 12);

        $alerts = $this->detect(self::EVAL_DAY.'T21:00:00Z', self::EVAL_DAY.'T23:00:00Z');

        self::assertCount(1, $alerts);
        self::assertEquals(new \DateTimeImmutable(self::EVAL_DAY.'T00:00:00Z'), $alerts[0]->windowStart);
    }

    public function test_backfill_ocenia_kazda_dobe_a_dwa_przebiegi_nie_dubluja_alertow(): void
    {
        $this->seller(101, [1, 2, 1, 2, 1, 2, 1], 12);
        $this->client->indices()->refresh(['index' => $this->index]);
        $job = new SecurityDetectJob(
            [new ComplaintsSpikeDetector($this->client, $this->index)],
            new AlertWriter($this->client, $this->alertsIndex),
        );
        $from = new \DateTimeImmutable('2026-11-10T00:00:00Z');
        $to = new \DateTimeImmutable('2026-11-25T00:00:00Z');

        self::assertSame(['complaints-spike' => 1], $job->detect($from, $to));
        self::assertSame(['complaints-spike' => 1], $job->detect($from, $to));

        $this->client->indices()->refresh(['index' => $this->alertsIndex]);
        self::assertSame(1, $this->client->count(['index' => $this->alertsIndex])->asArray()['count']);
    }
}
