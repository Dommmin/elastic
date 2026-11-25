<?php

namespace App\Tests\Integration\Security;

use App\Analytics\AnalyticsBulkWriter;
use App\Analytics\BulkOutcome;
use App\Message\IntegrationEvent;
use App\Security\AlertWriter;
use App\Security\RuleLoader;
use App\Security\SecurityDocumentMapper;
use App\Security\SecurityPercolator;
use App\Tests\Support\RealElasticsearch;
use Elastic\Elasticsearch\Client;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Reguły percolatora z plików repo na PRAWDZIWYM Elasticsearchu, w indeksach testowych
 * (`security-rules-test-*`, `alerts-security-test-*`), więc bez dotykania żywych reguł i alertów.
 * Dokumenty zdarzeń leżą w indeksie z mapowaniem `security-rules` (te same pola `events-security`),
 * bo w nim sami ustawiamy `product.popularity` (żywy pipeline bierze je z enrich). Wyjątek: test
 * data streamu, który idzie żywym streamem i regułą bez popularności.
 */
#[Group('integration')]
final class PercolatorRulesTest extends TestCase
{
    use RealElasticsearch;

    private const DETECTION_DIR = '/infra/elasticsearch/detection';
    private const P95 = 0.5;

    private Client $client;
    private string $suffix;
    private string $rulesIndex;
    private string $eventsIndex;
    private string $alertsIndex;
    private string $streamEventId = '';

    protected function setUp(): void
    {
        $this->client = $this->elasticsearchOrSkip();
        if (! is_dir(self::DETECTION_DIR.'/rules')) {
            self::markTestSkipped('Brak katalogu reguł '.self::DETECTION_DIR.'/rules.');
        }
        $this->suffix = uniqid();
        $this->rulesIndex = 'security-rules-test-'.$this->suffix;
        $this->eventsIndex = 'security-events-test-'.$this->suffix;
        $this->alertsIndex = 'alerts-security-test-'.$this->suffix;

        $rules = $this->mapping('index-security-rules.json');
        $this->client->indices()->create(['index' => $this->rulesIndex, 'body' => $rules]);
        $this->client->indices()->create(['index' => $this->eventsIndex, 'body' => $rules]);
        $this->client->indices()->create(['index' => $this->alertsIndex, 'body' => $this->mapping('index-alerts-security.json')]);

        // Próg wymuszony: percentyl 95 z żywego product-popularity zależy od danych.
        $loader = new RuleLoader($this->client, self::DETECTION_DIR.'/rules', $this->rulesIndex);
        $loaded = $loader->load(p95Override: self::P95);
        self::assertSame(['fresh-account-5star' => null, 'price-hug-threshold' => self::P95], $loaded);
    }

    protected function tearDown(): void
    {
        foreach ([$this->rulesIndex, $this->eventsIndex, $this->alertsIndex] as $index) {
            $this->client->indices()->delete(['index' => $index, 'ignore_unavailable' => true]);
        }
        if ($this->streamEventId !== '') {
            $this->client->deleteByQuery(['index' => SecurityDocumentMapper::STREAM, 'body' => ['query' => ['term' => ['event.id' => $this->streamEventId]]], 'refresh' => true, 'conflicts' => 'proceed']);
        }
    }

    /** @return array<string, mixed> */
    private function mapping(string $file): array
    {
        $body = json_decode(file_get_contents(self::DETECTION_DIR.'/'.$file), true, flags: \JSON_THROW_ON_ERROR);
        $body['settings']['index.number_of_replicas'] = 0;

        return $body;
    }

    private function percolator(): SecurityPercolator
    {
        return new SecurityPercolator($this->client, new AlertWriter($this->client, $this->alertsIndex), new NullLogger(), $this->rulesIndex);
    }

    /** @param array<string, mixed> $doc */
    private function store(string $id, array $doc): string
    {
        $this->client->index(['index' => $this->eventsIndex, 'id' => $id, 'body' => $doc, 'refresh' => 'true']);

        return BulkOutcome::ref($this->eventsIndex, $id);
    }

    private function price(string $id, float $pct, ?float $popularity, bool $synthetic = true): string
    {
        $doc = [
            '@timestamp' => '2026-11-25T10:00:00.000Z',
            'event' => ['id' => $id, 'type' => 'offer.price_changed'],
            'offer' => ['id' => 'offer-'.$id, 'old_price_cents' => 10000, 'new_price_cents' => 12500, 'change_pct' => $pct],
            'product' => ['id' => '999999991'] + ($popularity !== null ? ['popularity' => $popularity] : []),
            'seller' => ['id' => '999999992'],
        ];
        if ($synthetic) {
            $doc['labels'] = ['synthetic' => true];
        }

        return $this->store($id, $doc);
    }

    private function review(string $id, int $rating, int $ageHours): string
    {
        return $this->store($id, [
            '@timestamp' => '2026-11-25T11:00:00.000Z',
            'event' => ['id' => $id, 'type' => 'review.created'],
            'review' => ['id' => 'r-'.$id, 'rating' => $rating],
            'product' => ['id' => '999999991'],
            'user' => ['id' => 'u-'.$id, 'account_age_hours' => $ageHours],
            'labels' => ['synthetic' => true],
        ]);
    }

    /** @return list<string> posortowane `rule.id|entity.id` */
    private function alerts(): array
    {
        $this->client->indices()->refresh(['index' => $this->alertsIndex]);
        $hits = $this->client->search(['index' => $this->alertsIndex, 'body' => ['size' => 100, 'query' => ['match_all' => new \stdClass()]]])->asArray()['hits']['hits'];
        $out = array_map(static fn (array $h) => $h['_source']['rule']['id'].'|'.$h['_source']['entity']['id'], $hits);
        sort($out);

        return $out;
    }

    public function test_price_hug_strzela_tylko_dla_25_do_30_procent_przy_popularnosci_od_p95(): void
    {
        $refs = [
            $this->price('p2499', 24.99, 0.9),
            $this->price('p25', 25.0, 0.9),
            $this->price('p30', 30.0, 0.9),
            $this->price('p3001', 30.01, 0.9),
            $this->price('p275-low', 27.5, 0.4),
            $this->price('p275-none', 27.5, null),
            $this->price('p275-edge', 27.5, self::P95),
        ];

        self::assertSame(3, $this->percolator()->percolate($refs));
        self::assertSame(['price-hug-threshold|offer-p25', 'price-hug-threshold|offer-p275-edge', 'price-hug-threshold|offer-p30'], $this->alerts());
    }

    public function test_price_hug_nie_strzela_gdy_zmiana_procentowa_nie_istnieje(): void
    {
        $ref = $this->store('p-null', [
            '@timestamp' => '2026-11-25T10:00:00.000Z',
            'event' => ['id' => 'p-null', 'type' => 'offer.price_changed'],
            'offer' => ['id' => 'offer-null', 'old_price_cents' => 0, 'new_price_cents' => 5000],
            'product' => ['id' => '999999991', 'popularity' => 0.9],
        ]);

        self::assertSame(0, $this->percolator()->percolate([$ref]));
    }

    public function test_fresh_account_strzela_dla_5_gwiazdek_z_konta_mlodszego_niz_24h(): void
    {
        $refs = [
            $this->review('r23', 5, 23),
            $this->review('r24', 5, 24),
            $this->review('r4star', 4, 1),
        ];

        self::assertSame(1, $this->percolator()->percolate($refs));
        self::assertSame(['fresh-account-5star|u-r23'], $this->alerts());

        $doc = $this->client->search(['index' => $this->alertsIndex])->asArray()['hits']['hits'][0]['_source'];
        self::assertSame('user', $doc['entity']['type']);
        self::assertSame('percolator', $doc['rule']['kind']);
        self::assertSame('2026-11-25T11:00:00+00:00', $doc['window']['start']);
        self::assertSame($doc['window']['start'], $doc['window']['end']);
        self::assertTrue($doc['labels']['synthetic']);
        self::assertSame('23', (string) $doc['evidence']['user_account_age_hours']);
    }

    public function test_alert_dziedziczy_znacznik_synthetic_ze_zdarzenia(): void
    {
        $ref = $this->price('p-real', 26.0, 0.9, synthetic: false);

        $this->percolator()->percolate([$ref]);

        $this->client->indices()->refresh(['index' => $this->alertsIndex]);
        $doc = $this->client->search(['index' => $this->alertsIndex])->asArray()['hits']['hits'][0]['_source'];
        self::assertFalse($doc['labels']['synthetic']);
    }

    public function test_ponowna_percolacja_tych_samych_dokumentow_nie_zmienia_liczby_alertow(): void
    {
        $refs = [$this->price('p25', 25.0, 0.9), $this->review('r1', 5, 2)];
        $percolator = $this->percolator();

        self::assertSame(2, $percolator->percolate($refs));
        self::assertSame(2, $percolator->percolate($refs));

        self::assertCount(2, $this->alerts());
    }

    public function test_dokument_z_data_streamu_jest_percolowany_przez_referencje_do_indeksu_zapasowego(): void
    {
        // Ref z handlera wskazuje NAZWĘ streamu; percolate po referencji potrzebuje konkretnego indeksu.
        $this->streamEventId = 'it-percolate-'.$this->suffix;
        $event = new IntegrationEvent($this->streamEventId, 'review.created', 1, 'catalog', new \DateTimeImmutable('2026-11-25T11:00:00+00:00'), 'review', '1', 1, [
            'id' => 1, 'product_id' => 999999991, 'user_id' => 920000777, 'rating' => 5, 'user_account_age_hours' => 3, 'synthetic' => true,
        ]);
        $items = (new SecurityDocumentMapper())->map($event);
        $outcome = (new AnalyticsBulkWriter($this->client))->write($items);
        self::assertSame(1, $outcome->created);

        $ref = BulkOutcome::ref($items[0]['index'], $items[0]['id']);
        self::assertSame(1, $this->percolator()->percolate([$ref]));
        self::assertSame(['fresh-account-5star|920000777'], $this->alerts());
    }
}
