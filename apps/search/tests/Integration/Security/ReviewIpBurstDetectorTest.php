<?php

namespace App\Tests\Integration\Security;

use App\Security\AlertWriter;
use App\Security\Detect\ReviewIpBurstDetector;
use App\Security\SecurityDetectJob;
use App\Tests\Support\RealElasticsearch;
use Elastic\Elasticsearch\Client;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Backfill `review-ip-burst` na PRAWDZIWYM Elasticsearchu: ten sam tekst ES|QL co w regule Kibany
 * (plik `infra/kibana/rules`), tylko indeks zdarzeń podmieniony na testowy (`security-events-test-*`).
 */
#[Group('integration')]
final class ReviewIpBurstDetectorTest extends TestCase
{
    use RealElasticsearch;

    private const DETECTION_DIR = '/infra/elasticsearch/detection';
    private const RULE_FILE = '/infra/kibana/rules/security-review-ip-burst.json';

    private Client $client;
    private string $eventsIndex;
    private string $alertsIndex;
    private int $seq = 0;

    protected function setUp(): void
    {
        $this->client = $this->elasticsearchOrSkip();
        if (! is_file(self::RULE_FILE) || ! is_dir(self::DETECTION_DIR)) {
            self::markTestSkipped('Brak pliku reguły '.self::RULE_FILE.' lub '.self::DETECTION_DIR.' (mounty search-consumer).');
        }
        $suffix = uniqid();
        $this->eventsIndex = 'security-events-test-'.$suffix;
        $this->alertsIndex = 'alerts-security-test-'.$suffix;
        $this->client->indices()->create(['index' => $this->eventsIndex, 'body' => $this->mapping('index-security-rules.json')]);
        $this->client->indices()->create(['index' => $this->alertsIndex, 'body' => $this->mapping('index-alerts-security.json')]);
    }

    protected function tearDown(): void
    {
        foreach ([$this->eventsIndex, $this->alertsIndex] as $index) {
            $this->client->indices()->delete(['index' => $index, 'ignore_unavailable' => true]);
        }
    }

    /** @return array<string, mixed> */
    private function mapping(string $file): array
    {
        $body = json_decode(file_get_contents(self::DETECTION_DIR.'/'.$file), true, flags: \JSON_THROW_ON_ERROR);
        $body['settings']['index.number_of_replicas'] = 0;

        return $body;
    }

    private function review(string $hash, string $user, string $at = '2026-11-25T10:15:00Z', bool $synthetic = true): void
    {
        $doc = [
            '@timestamp' => $at,
            'event' => ['id' => 'e'.++$this->seq, 'type' => 'review.created'],
            'review' => ['id' => 'r'.$this->seq, 'rating' => 5],
            'user' => ['id' => $user],
            'client' => ['ip_hash' => $hash],
        ];
        if ($synthetic) {
            $doc['labels'] = ['synthetic' => true];
        }
        $this->client->index(['index' => $this->eventsIndex, 'body' => $doc]);
    }

    private function refresh(): void
    {
        $this->client->indices()->refresh(['index' => $this->eventsIndex]);
    }

    private function detector(): ReviewIpBurstDetector
    {
        return new ReviewIpBurstDetector($this->client, self::RULE_FILE, $this->eventsIndex);
    }

    private function detectAll(): array
    {
        return $this->detector()->detect(new \DateTimeImmutable('2026-11-25T00:00:00Z'), new \DateTimeImmutable('2026-11-25T23:00:00Z'));
    }

    public function test_piec_roznych_uzytkownikow_z_jednego_hasza_w_godzinie_daje_alert(): void
    {
        foreach (range(1, 5) as $i) {
            $this->review('aaaa000000000001', 'u'.$i);
        }
        $this->refresh();

        $alerts = $this->detectAll();

        self::assertCount(1, $alerts);
        $a = $alerts[0];
        self::assertSame('review-ip-burst', $a->ruleId);
        self::assertSame('esql', $a->ruleKind);
        self::assertSame('ip_hash', $a->entityType);
        self::assertSame('aaaa000000000001', $a->entityId);
        self::assertEquals(new \DateTimeImmutable('2026-11-25T10:00:00Z'), $a->windowStart);
        self::assertEquals(new \DateTimeImmutable('2026-11-25T11:00:00Z'), $a->windowEnd);
        self::assertTrue($a->synthetic);
        self::assertSame(5, $a->evidence['users']);
    }

    public function test_czterech_uzytkownikow_nie_daje_alertu(): void
    {
        foreach (range(1, 4) as $i) {
            $this->review('aaaa000000000002', 'u'.$i);
        }
        $this->refresh();

        self::assertSame([], $this->detectAll());
    }

    public function test_piec_recenzji_jednego_uzytkownika_nie_daje_alertu(): void
    {
        foreach (range(1, 5) as $_) {
            $this->review('aaaa000000000003', 'jedyny');
        }
        $this->refresh();

        self::assertSame([], $this->detectAll());
    }

    public function test_zdarzenie_bez_hasza_i_poza_zakresem_czasu_sie_nie_liczy(): void
    {
        foreach (range(1, 5) as $i) {
            $this->review('aaaa000000000004', 'u'.$i, '2026-11-20T10:15:00Z');
        }
        $this->refresh();

        self::assertSame([], $this->detectAll());
    }

    public function test_dwa_przebiegi_zostawiaja_jeden_dokument_w_alerts_security(): void
    {
        foreach (range(1, 5) as $i) {
            $this->review('aaaa000000000005', 'u'.$i);
        }
        $this->refresh();
        $job = new SecurityDetectJob([$this->detector()], new AlertWriter($this->client, $this->alertsIndex));
        $from = new \DateTimeImmutable('2026-11-25T00:00:00Z');
        $to = new \DateTimeImmutable('2026-11-25T23:00:00Z');

        self::assertSame(['review-ip-burst' => 1], $job->detect($from, $to));
        self::assertSame(['review-ip-burst' => 1], $job->detect($from, $to));

        $this->client->indices()->refresh(['index' => $this->alertsIndex]);
        self::assertSame(1, $this->client->count(['index' => $this->alertsIndex])->asArray()['count']);
    }
}
