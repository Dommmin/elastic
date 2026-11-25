<?php

namespace App\Tests\Integration\Security;

use App\Analytics\AnalyticsBulkWriter;
use App\Message\IntegrationEvent;
use App\MessageHandler\SecurityEventHandler;
use App\Security\NullEventPercolator;
use App\Security\SecurityDocumentMapper;
use App\Tests\Support\RealElasticsearch;
use Elastic\Elasticsearch\Client;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Handler\Acknowledger;

/**
 * Zapis do żywego data streamu `events-security-default` (ETAP 15) przez prawdziwy handler i klienta ES.
 * Dokumenty mają unikalne id zdarzeń i `labels.synthetic`; tearDown kasuje je po id.
 * Produkt spoza `product-popularity` (id z dala od katalogu) nie dostaje `product.popularity`,
 * a zapis i tak przechodzi (pipeline z `on_failure`).
 */
#[Group('integration')]
final class SecurityIngestTest extends TestCase
{
    use RealElasticsearch;

    private Client $client;
    private string $eventId;

    /** @var array<string, \Throwable|string|null> */
    private array $results = [];

    protected function setUp(): void
    {
        $this->client = $this->elasticsearchOrSkip();
        if (! $this->streamExists()) {
            self::markTestSkipped('Brak data streamu events-security-default (make detection-setup).');
        }
        $this->eventId = 'it-security-'.uniqid();
    }

    protected function tearDown(): void
    {
        if (isset($this->client, $this->eventId)) {
            $this->client->deleteByQuery([
                'index' => SecurityDocumentMapper::STREAM,
                'body' => ['query' => ['term' => ['event.id' => $this->eventId]]],
                'refresh' => true,
                'conflicts' => 'proceed',
            ]);
        }
    }

    private function streamExists(): bool
    {
        try {
            return $this->client->indices()->getDataStream(['name' => SecurityDocumentMapper::STREAM])->asArray()['data_streams'] !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    private function send(SecurityEventHandler $handler, array $data, string $type = 'offer.price_changed'): void
    {
        $event = new IntegrationEvent($this->eventId, $type, 1, 'catalog', new \DateTimeImmutable('now', new \DateTimeZone('UTC')), 'offer', '777000001', 1, $data);
        $handler($event, new Acknowledger('test', function (?\Throwable $e): void {
            $this->results[] = $e ?? 'ack';
        }));
        $handler->flush(true);
    }

    /** @return array<string, mixed>|null */
    private function stored(): ?array
    {
        $this->client->indices()->refresh(['index' => SecurityDocumentMapper::STREAM]);
        $hits = $this->client->search([
            'index' => SecurityDocumentMapper::STREAM,
            'body' => ['query' => ['term' => ['event.id' => $this->eventId]]],
        ])->asArray()['hits']['hits'];

        return $hits === [] ? null : $hits[0]['_source'];
    }

    public function test_zmiana_ceny_produktu_spoza_popularnosci_zapisuje_sie_bez_product_popularity_a_powtorka_to_duplikat(): void
    {
        $writer = new AnalyticsBulkWriter($this->client);
        $handler = new SecurityEventHandler(new SecurityDocumentMapper(), $writer, new NullEventPercolator(), new NullLogger());
        $data = ['product_id' => 999999991, 'seller_id' => 999999992, 'old_price_cents' => 10000, 'new_price_cents' => 12750, 'synthetic' => true, 'simulation' => 'security'];

        $this->send($handler, $data);

        self::assertSame(['ack'], $this->results);
        $doc = $this->stored();
        self::assertNotNull($doc);
        self::assertSame(27.5, $doc['offer']['change_pct']);
        self::assertSame('999999991', $doc['product']['id']);
        self::assertArrayNotHasKey('popularity', $doc['product']);
        self::assertTrue($doc['labels']['synthetic']);
        self::assertArrayHasKey('ingested', $doc['event']);

        // Ten sam event drugi raz: 409 -> duplicates = 1.
        $outcome = $writer->write((new SecurityDocumentMapper())->map(
            new IntegrationEvent($this->eventId, 'offer.price_changed', 1, 'catalog', new \DateTimeImmutable('now'), 'offer', '777000001', 1, $data),
        ));
        self::assertSame(1, $outcome->duplicates);
        self::assertSame(0, $outcome->created);
    }

    public function test_recenzja_z_odciskiem_klienta_przechodzi_scisle_mapowanie(): void
    {
        $handler = new SecurityEventHandler(new SecurityDocumentMapper(), new AnalyticsBulkWriter($this->client), new NullEventPercolator(), new NullLogger());

        $this->send($handler, [
            'id' => 1, 'product_id' => 999999991, 'user_id' => 920000001, 'rating' => 5,
            'client_ip_hash' => '0123456789abcdef', 'client_ip_prefix' => '203.0.113.0/24',
            'client_user_agent' => 'PHPUnit', 'user_account_age_hours' => 2, 'synthetic' => true,
        ], 'review.created');

        self::assertSame(['ack'], $this->results);
        $doc = $this->stored();
        self::assertSame('0123456789abcdef', $doc['client']['ip_hash']);
        self::assertSame(2, $doc['user']['account_age_hours']);
        self::assertSame(5, $doc['review']['rating']);
    }
}
