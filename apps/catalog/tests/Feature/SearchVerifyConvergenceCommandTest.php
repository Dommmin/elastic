<?php

use App\Models\Offer;
use App\Models\Outbox;
use App\Models\Product;
use App\Services\Search\ProductProjectionBuilder;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * `search:verify-convergence` bez żywego ES (rola `catalog` nie tworzy indeksów):
 * sztuczny transport odpowiada na `_mget` dokumentami z tablicy `$docs`.
 *
 * @param  array<string, array<string, mixed>>  $docs  id => _source
 */
function fakeConvergenceEs(array &$docs, array &$log): ClientInterface
{
    return new class($docs, $log) implements ClientInterface
    {
        public function __construct(private array &$docs, private array &$log) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $path = $request->getUri()->getPath();
            $body = json_decode((string) $request->getBody(), true) ?? [];
            $this->log[] = $request->getMethod().' '.$path.' '.count($body['ids'] ?? $body['docs'] ?? []);

            $ids = $body['ids'] ?? array_column($body['docs'] ?? [], '_id');
            $result = array_map(fn ($id) => isset($this->docs[$id])
                ? ['_id' => (string) $id, 'found' => true, '_source' => $this->docs[$id]]
                : ['_id' => (string) $id, 'found' => false], $ids);

            return new Response(200, ['X-Elastic-Product' => 'Elasticsearch', 'Content-Type' => 'application/json'], json_encode(['docs' => $result]));
        }
    };
}

function projectionDoc(Product $product): array
{
    return json_decode(json_encode(app(ProductProjectionBuilder::class)->build($product->id, 1)), true);
}

function outboxFor(string $type, string $aggregateId, array $payload = []): void
{
    Outbox::create([
        'event_id' => (string) Str::ulid(),
        'aggregate_type' => $type,
        'aggregate_id' => $aggregateId,
        'event_type' => $type.'.updated',
        'payload' => $payload,
        'sequence' => 1,
        'occurred_at' => now(),
    ]);
}

beforeEach(function () {
    $this->docs = [];
    $this->log = [];
    $this->app->instance(Client::class, ClientBuilder::create()
        ->setHosts(['http://es.invalid:9200'])
        ->setHttpClient(fakeConvergenceEs($this->docs, $this->log))
        ->build());
});

test('zgodny dokument: mismatches=0, kod 0, ID z ofert i opinii rozwiązane do produktu', function () {
    $product = Product::factory()->create();
    $offer = Offer::factory()->create(['product_id' => $product->id, 'price_cents' => 1999, 'stock' => 3]);
    outboxFor('offer', (string) $offer->id, ['product_id' => $product->id]);
    outboxFor('product', (string) $product->id);
    outboxFor('seller', '5');
    $this->docs[(string) $product->id] = projectionDoc($product);

    $this->artisan('search:verify-convergence', ['--since' => now()->subHour()->toIso8601String()])
        ->expectsOutputToContain('checked=1 mismatches=0')
        ->assertExitCode(0);
});

test('inna cena w ES: mismatch z ID produktu, kod ≠ 0', function () {
    $product = Product::factory()->create();
    Offer::factory()->create(['product_id' => $product->id, 'price_cents' => 1999, 'stock' => 3]);
    outboxFor('product', (string) $product->id);
    $doc = projectionDoc($product);
    $doc['price_min'] = 1500;
    $this->docs[(string) $product->id] = $doc;

    $this->artisan('search:verify-convergence', ['--since' => now()->subHour()->toIso8601String()])
        ->expectsOutputToContain('checked=1 mismatches=1')
        ->expectsOutputToContain("{$product->id}: price_min")
        ->assertExitCode(1);
});

test('różnice w zbiorze ofert i in_stock są raportowane; różnica poniżej 0.005 nie jest', function () {
    $product = Product::factory()->create();
    Offer::factory()->create(['product_id' => $product->id, 'price_cents' => 1999, 'stock' => 3]);
    outboxFor('product', (string) $product->id);
    $doc = projectionDoc($product);
    $doc['price_max'] += 0.004;
    $this->docs[(string) $product->id] = $doc;

    $this->artisan('search:verify-convergence', ['--since' => now()->subHour()->toIso8601String()])
        ->expectsOutputToContain('mismatches=0')
        ->assertExitCode(0);

    $doc['offers'][0]['offer_id'] = '999999';
    $doc['in_stock'] = ! $doc['in_stock'];
    $this->docs[(string) $product->id] = $doc;

    $exit = Artisan::call('search:verify-convergence', ['--since' => now()->subHour()->toIso8601String()]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('offers.offer_id')->toContain('in_stock');
});

test('zombie: produkt usunięty z bazy, a dokument nadal w ES', function () {
    outboxFor('product', '777');
    $this->docs['777'] = ['price_min' => 1, 'price_max' => 1, 'in_stock' => true, 'offers' => []];

    $this->artisan('search:verify-convergence', ['--since' => now()->subHour()->toIso8601String()])
        ->expectsOutputToContain('mismatches=1')
        ->expectsOutputToContain('777: zombie')
        ->assertExitCode(1);
});

test('brak dokumentu dla istniejącego produktu to mismatch; usunięty i nieobecny w ES jest zgodny', function () {
    $product = Product::factory()->create();
    outboxFor('product', (string) $product->id);
    outboxFor('product', '778');

    $this->artisan('search:verify-convergence', ['--since' => now()->subHour()->toIso8601String()])
        ->expectsOutputToContain('checked=2 mismatches=1')
        ->expectsOutputToContain("{$product->id}: brak dokumentu")
        ->assertExitCode(1);
});

test('zdarzenia sprzed --since są pomijane, a odczyt idzie partiami przez mget', function () {
    $old = Product::factory()->create();
    outboxFor('product', (string) $old->id);
    Outbox::query()->update(['occurred_at' => now()->subDays(2)]);

    foreach (Product::factory()->count(3)->create() as $p) {
        outboxFor('product', (string) $p->id);
        $this->docs[(string) $p->id] = projectionDoc($p);
    }

    $this->artisan('search:verify-convergence', ['--since' => now()->subHour()->toIso8601String(), '--batch' => 2])
        ->expectsOutputToContain('checked=3 mismatches=0')
        ->assertExitCode(0);

    expect($this->log)->toHaveCount(2);
});

test('--since z przesunięciem strefy nie przesuwa okna', function () {
    $old = Product::factory()->create();
    outboxFor('product', (string) $old->id);
    Outbox::query()->update(['occurred_at' => now()->subMinutes(50)]);
    $fresh = Product::factory()->create();
    outboxFor('product', (string) $fresh->id);
    $this->docs[(string) $fresh->id] = projectionDoc($fresh);

    // now()-30 min zapisane w -05:00: bez sprowadzenia do UTC okno zaczęłoby się 5 h wcześniej.
    $this->artisan('search:verify-convergence', ['--since' => now()->subMinutes(30)->setTimezone('-05:00')->toIso8601String()])
        ->expectsOutputToContain('checked=1 mismatches=0')
        ->assertExitCode(0);
});
