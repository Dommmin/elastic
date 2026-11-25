<?php

namespace App\Tests\Integration\Security;

use App\Security\AlertWriter;
use App\Security\Detect\PriceBaitAndSwitchDetector;
use App\Security\SecurityDetectJob;
use App\Tests\Support\RealElasticsearch;
use Elastic\Elasticsearch\Client;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * `price-bait-and-switch` na PRAWDZIWYM Elasticsearchu: ten sam plik `.eql` co w produkcji, tylko wzorce
 * indeksów podmienione na testowe (`events-test-security-*`, `events-test-user-*`).
 */
#[Group('integration')]
final class PriceBaitAndSwitchDetectorTest extends TestCase
{
    use RealElasticsearch;

    private const DETECTION_DIR = '/infra/elasticsearch/detection';
    private const EQL_FILE = '/infra/elasticsearch/detection/eql/price-bait-and-switch.eql';
    private const T0 = '2026-11-20T08:00:00Z';

    private Client $client;
    private string $securityIndex;
    private string $userIndex;
    private string $alertsIndex;
    private int $seq = 0;

    protected function setUp(): void
    {
        $this->client = $this->elasticsearchOrSkip();
        if (! is_file(self::EQL_FILE) || ! is_file('/infra/elasticsearch/analytics/template-events-user.json')) {
            self::markTestSkipped('Brak pliku '.self::EQL_FILE.' lub szablonu events-user (mounty search-consumer).');
        }
        $suffix = uniqid();
        $this->securityIndex = 'events-test-security-'.$suffix;
        $this->userIndex = 'events-test-user-'.$suffix;
        $this->alertsIndex = 'alerts-security-test-'.$suffix;
        $this->client->indices()->create(['index' => $this->securityIndex, 'body' => $this->mappings('/infra/elasticsearch/detection/template-events-security.json')]);
        $this->client->indices()->create(['index' => $this->userIndex, 'body' => $this->mappings('/infra/elasticsearch/analytics/template-events-user.json')]);
        $this->client->indices()->create(['index' => $this->alertsIndex, 'body' => $this->alertsMapping()]);
    }

    protected function tearDown(): void
    {
        foreach ([$this->securityIndex, $this->userIndex, $this->alertsIndex] as $index) {
            $this->client->indices()->delete(['index' => $index, 'ignore_unavailable' => true]);
        }
    }

    /** @return array<string, mixed> */
    private function mappings(string $templateFile): array
    {
        $template = json_decode(file_get_contents($templateFile), true, flags: \JSON_THROW_ON_ERROR)['template'];

        return ['settings' => ['index.number_of_replicas' => 0], 'mappings' => $template['mappings']];
    }

    /** @return array<string, mixed> */
    private function alertsMapping(): array
    {
        $body = json_decode(file_get_contents(self::DETECTION_DIR.'/index-alerts-security.json'), true, flags: \JSON_THROW_ON_ERROR);
        $body['settings']['index.number_of_replicas'] = 0;

        return $body;
    }

    private function at(float $hours): string
    {
        return (new \DateTimeImmutable(self::T0))->modify(\sprintf('+%d seconds', (int) round($hours * 3600)))->format('Y-m-d\TH:i:s\Z');
    }

    private function priceChange(string $offer, float $hours, int $old, int $new, bool $synthetic = true): void
    {
        $doc = [
            '@timestamp' => $this->at($hours),
            'event' => ['id' => 'e'.++$this->seq, 'type' => 'offer.price_changed'],
            'offer' => ['id' => $offer, 'old_price_cents' => $old, 'new_price_cents' => $new, 'change_pct' => round(($new - $old) / $old * 100, 2)],
        ];
        if ($synthetic) {
            $doc['labels'] = ['synthetic' => true];
        }
        $this->client->index(['index' => $this->securityIndex, 'body' => $doc]);
    }

    private function clicks(string $offer, int $count, float $fromHour, float $toHour): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $this->client->index(['index' => $this->userIndex, 'body' => [
                '@timestamp' => $this->at($fromHour + ($toHour - $fromHour) * ($i + 1) / ($count + 1)),
                'event' => ['id' => 'c'.++$this->seq, 'type' => 'user.clicked_result'],
                'offer' => ['id' => $offer],
            ]]);
        }
    }

    /** Obniżka 10000 -> 8000, $clicks kliknięć, podwyżki do 10320 (+29) i 13106 (+27 = 1,31x); całość rozciągnięta na $span godzin. */
    private function scenario(string $offer, int $clicks, float $span): void
    {
        $this->priceChange($offer, 0, 10000, 8000);
        $this->clicks($offer, $clicks, 0, $span);
        $this->priceChange($offer, $span - 1, 8000, 10320);
        $this->priceChange($offer, $span, 10320, 13106);
        $this->client->indices()->refresh(['index' => $this->securityIndex.','.$this->userIndex]);
    }

    private function detector(): PriceBaitAndSwitchDetector
    {
        return new PriceBaitAndSwitchDetector($this->client, self::EQL_FILE, $this->securityIndex, $this->userIndex);
    }

    private function detectAll(): array
    {
        return $this->detector()->detect(new \DateTimeImmutable('2026-11-01T00:00:00Z'), new \DateTimeImmutable('2026-11-30T00:00:00Z'));
    }

    public function test_obnizka_20_klikniec_i_dwie_podwyzki_w_30_godzin_daja_alert(): void
    {
        $this->scenario('offer-1', 20, 30);

        $alerts = $this->detectAll();

        self::assertCount(1, $alerts);
        $a = $alerts[0];
        self::assertSame('price-bait-and-switch', $a->ruleId);
        self::assertSame('eql', $a->ruleKind);
        self::assertSame('offer', $a->entityType);
        self::assertSame('offer-1', $a->entityId);
        self::assertEquals(new \DateTimeImmutable($this->at(0)), $a->windowStart);
        self::assertEquals(new \DateTimeImmutable($this->at(30)), $a->windowEnd);
        self::assertTrue($a->synthetic);
        self::assertSame(20, $a->evidence['clicks']);
        self::assertSame(10000, $a->evidence['price_before_cents']);
        self::assertSame(8000, $a->evidence['price_dropped_cents']);
        self::assertSame(13106, $a->evidence['price_final_cents']);
    }

    public function test_te_same_zdarzenia_rozciagniete_na_60_godzin_nie_daja_alertu(): void
    {
        $this->scenario('offer-2', 20, 60);

        self::assertSame([], $this->detectAll());
    }

    public function test_dziewietnascie_klikniec_nie_daje_alertu(): void
    {
        $this->scenario('offer-3', 19, 30);

        self::assertSame([], $this->detectAll());
    }

    public function test_klikniecia_innej_oferty_sie_nie_licza(): void
    {
        $this->scenario('offer-4', 0, 30);
        $this->clicks('inna-oferta', 25, 0, 30);
        $this->client->indices()->refresh(['index' => $this->userIndex]);

        self::assertSame([], $this->detectAll());
    }

    public function test_post_filtr_odrzuca_sekwencje_z_cena_koncowa_ponizej_1_3(): void
    {
        $this->priceChange('offer-5', 0, 10000, 8000);
        $this->clicks('offer-5', 20, 0, 30);
        $this->priceChange('offer-5', 29, 8000, 9600);
        $this->priceChange('offer-5', 30, 9600, 11136);
        $this->client->indices()->refresh(['index' => $this->securityIndex.','.$this->userIndex]);

        self::assertSame([], $this->detectAll());
    }

    public function test_alert_dotyczy_tylko_sekwencji_konczacych_sie_w_zakresie(): void
    {
        $this->scenario('offer-6', 20, 30);

        $before = $this->detector()->detect(new \DateTimeImmutable($this->at(-100)), new \DateTimeImmutable($this->at(29)));
        $overlap = $this->detector()->detect(new \DateTimeImmutable($this->at(29.5)), new \DateTimeImmutable($this->at(31)));

        self::assertSame([], $before);
        self::assertCount(1, $overlap);
    }

    public function test_alert_nie_jest_syntetyczny_gdy_zdarzenia_cen_nie_maja_flagi(): void
    {
        $this->priceChange('offer-7', 0, 10000, 8000, false);
        $this->clicks('offer-7', 20, 0, 30);
        $this->priceChange('offer-7', 29, 8000, 10320, false);
        $this->priceChange('offer-7', 30, 10320, 13106, false);
        $this->client->indices()->refresh(['index' => $this->securityIndex.','.$this->userIndex]);

        $alerts = $this->detectAll();

        self::assertCount(1, $alerts);
        self::assertFalse($alerts[0]->synthetic);
    }

    public function test_dwa_przebiegi_zostawiaja_jeden_dokument_w_alerts_security(): void
    {
        $this->scenario('offer-8', 20, 30);
        $job = new SecurityDetectJob([$this->detector()], new AlertWriter($this->client, $this->alertsIndex));
        $from = new \DateTimeImmutable('2026-11-01T00:00:00Z');
        $to = new \DateTimeImmutable('2026-11-30T00:00:00Z');

        self::assertSame(['price-bait-and-switch' => 1], $job->detect($from, $to));
        self::assertSame(['price-bait-and-switch' => 1], $job->detect($from, $to));

        $this->client->indices()->refresh(['index' => $this->alertsIndex]);
        self::assertSame(1, $this->client->count(['index' => $this->alertsIndex])->asArray()['count']);
    }
}
