<?php

namespace App\Tests\Integration;

use App\Tests\Support\RealElasticsearch;
use Elastic\Elasticsearch\Client;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Transform `seller-sales-daily` (ETAP 13) na ŻYWYM stosie: wiersze trafiają do prawdziwego
 * data streamu `events-seller-default`, transform (żywy, ciągły) liczy z nich kubełek dnia.
 *
 * Wiersze mają `@timestamp` sprzed 40 dni i unikalne id sprzedawcy (`it-<uniqid>`), więc nie
 * mieszają się z żadnym realnym kubełkiem. Transform bierze tylko wiersze starsze niż delay
 * 60 s względem `event.ingested`, dlatego test czeka ~65 s i woła `_schedule_now`.
 *
 * Sprzątanie: wiersze kasowane po `_id` z backing indeksów (data streamu nie ruszamy:
 * bez delete/rollover), kubełek w `seller-sales-daily` po id sprzedawcy.
 */
#[Group('integration')]
final class SellerSalesTransformTest extends TestCase
{
    use RealElasticsearch;

    private const STREAM = 'events-seller-default';
    private const TRANSFORM = 'seller-sales-daily';

    private Client $client;
    private string $seller;
    private string $day;
    /** @var list<string> */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->client = $this->elasticsearchOrSkip();
        try {
            $this->client->transform()->getTransformStats(['transform_id' => self::TRANSFORM]);
        } catch (\Throwable) {
            self::markTestSkipped('Brak transformu '.self::TRANSFORM.' — uruchom `make analytics-setup`.');
        }

        $this->seller = 'it-'.uniqid();
        $this->day = (new \DateTimeImmutable('-40 days', new \DateTimeZone('UTC')))->format('Y-m-d');
    }

    protected function tearDown(): void
    {
        if (! isset($this->seller)) {
            return;
        }
        $this->client->indices()->refresh(['index' => self::STREAM]);
        $hits = $this->client->search(['index' => self::STREAM, 'body' => [
            'size' => 100,
            'query' => ['term' => ['seller.id' => $this->seller]],
        ]])->asArray()['hits']['hits'];
        foreach ($hits as $hit) {
            $this->client->delete(['index' => $hit['_index'], 'id' => $hit['_id']]);
        }
        $this->client->deleteByQuery([
            'index' => self::TRANSFORM,
            'conflicts' => 'proceed',
            'refresh' => true,
            'body' => ['query' => ['term' => ['seller_id' => $this->seller]]],
        ]);
    }

    public function test_kubelek_dnia_sprzed_40_dni_liczy_sesje_koszyka_zamowienia_i_gmv(): void
    {
        $at = "{$this->day}T12:00:00.000Z";
        $this->index('e1', 'cart', 'sess-A', $at);
        $this->index('e2', 'cart', 'sess-B', $at);
        $this->index('e3', 'order', 'sess-A', $at, 1500);

        // Powtórka tego samego `_id` to 409, nie drugi wiersz (dedup jak w ETAPIE 12).
        $duplicate = $this->client->bulk(['require_data_stream' => true, 'body' => [
            ['create' => ['_index' => self::STREAM, '_id' => "e3:{$this->seller}"]],
            $this->row('e3', 'order', 'sess-A', $at, 1500),
        ]])->asArray();
        $this->assertSame(409, $duplicate['items'][0]['create']['status']);

        // delay 60 s liczone od event.ingested: wcześniej `_schedule_now` nie zobaczy wierszy
        sleep(65);

        $bucket = $this->waitForBucket();

        $this->assertSame(2, $bucket['cart_sessions']);
        $this->assertSame(1, $bucket['order_sessions']);
        $this->assertSame(1500, $bucket['gmv_cents']);
        $this->assertSame($this->seller, $bucket['seller_id']);
        $this->assertStringStartsWith($this->day, $bucket['day']);
    }

    private function index(string $event, string $kind, string $session, string $at, ?int $cents = null): void
    {
        $this->client->create([
            'index' => self::STREAM,
            'id' => "{$event}:{$this->seller}",
            'body' => $this->row($event, $kind, $session, $at, $cents),
        ]);
        $this->ids[] = "{$event}:{$this->seller}";
    }

    /** @return array<string, mixed> */
    private function row(string $event, string $kind, string $session, string $at, ?int $cents): array
    {
        $row = [
            '@timestamp' => $at,
            'event' => ['id' => $event, 'type' => $kind === 'cart' ? 'user.added_to_cart' : 'user.checked_out'],
            'session' => ['id' => $session],
            'seller' => ['id' => $this->seller],
            'kind' => $kind,
        ];
        if ($cents !== null) {
            $row['amount_cents'] = $cents;
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function waitForBucket(): array
    {
        $this->client->indices()->refresh(['index' => self::STREAM]);
        for ($attempt = 0; $attempt < 30; ++$attempt) {
            // Przy trwającym checkpointcie `_schedule_now` jest bezpiecznym no-opem: ponawiamy.
            try {
                $this->client->transform()->scheduleNowTransform(['transform_id' => self::TRANSFORM]);
            } catch (\Throwable) {
            }
            sleep(5);
            $this->client->indices()->refresh(['index' => self::TRANSFORM]);
            $hits = $this->client->search(['index' => self::TRANSFORM, 'body' => [
                'query' => ['term' => ['seller_id' => $this->seller]],
            ]])->asArray()['hits']['hits'];
            if ($hits !== []) {
                return $hits[0]['_source'];
            }
        }
        $this->fail('Kubełek seller-sales-daily nie pojawił się po ~150 s.');
    }
}
