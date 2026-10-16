<?php

use App\Models\Offer as OfferRow;
use App\Models\Seller as SellerRow;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Ordering\Domain\OrderId;
use Ordering\Domain\OrderRepository;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Offer\OfferRepository;
use SharedKernel\ConcurrencyConflict;

/*
 * Właściwy pomiar wymaga Postgresa i równoległych procesów (kontener). Tu
 * sprawdzamy to, co da się sprawdzić na SQLite: odmowę, plan `--dry-run`,
 * komendę pojedynczego kupującego i licznik konfliktów w logu.
 */

/**
 * @return array<string, int>
 */
function raceTableCounts(): array
{
    return collect(['users', 'sellers', 'products', 'offers', 'orders', 'jobs'])
        ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])
        ->all();
}

function raceConflictLog(): string
{
    $path = storage_path('framework/testing/concurrency-'.getmypid().'.log');
    @unlink($path);
    config(['logging.channels.concurrency.path' => $path]);

    return $path;
}

afterEach(fn () => @unlink(storage_path('framework/testing/concurrency-'.getmypid().'.log')));

test('na SQLite odmawia z kodem 1 i niczego nie tworzy', function () {
    $before = raceTableCounts();

    $this->artisan('orders:race-test')
        ->expectsOutputToContain('pgsql')
        ->assertExitCode(1);

    expect(raceTableCounts())->toBe($before);
});

test('--dry-run wypisuje plan i kończy się kodem 0 bez dotykania bazy', function () {
    $before = raceTableCounts();

    $this->artisan('orders:race-test', ['--dry-run' => true, '--buyers' => 30, '--stock' => 7, '--workers' => 3])
        ->expectsTable(['Parametr', 'Wartość'], [
            ['Kupujący (procesy)', '30'],
            ['Stan oferty', '7'],
            ['Workery kolejki', '3'],
            ['Połączenie kolejki', 'sync'],
            ['Oczekiwane opłacone', '7'],
            ['Fixture', 'race-{run}-{n}@example.test'],
        ])
        ->assertExitCode(0);

    expect(raceTableCounts())->toBe($before);
});

test('--dry-run z wartościami domyślnymi: 50 kupujących, 10 sztuk, 4 workery', function () {
    $this->artisan('orders:race-test', ['--dry-run' => true])
        ->expectsTable(['Parametr', 'Wartość'], [
            ['Kupujący (procesy)', '50'],
            ['Stan oferty', '10'],
            ['Workery kolejki', '4'],
            ['Połączenie kolejki', 'sync'],
            ['Oczekiwane opłacone', '10'],
            ['Fixture', 'race-{run}-{n}@example.test'],
        ])
        ->assertExitCode(0);
});

test('odrzuca niedodatnie parametry kodem 1', function () {
    $this->artisan('orders:race-test', ['--dry-run' => true, '--buyers' => 0])
        ->expectsOutputToContain('--buyers')
        ->assertExitCode(1);
});

test('komenda kupującego jest ukryta i składa zamówienie prawdziwym handlerem', function () {
    $offer = OfferRow::factory()->create([
        'seller_id' => SellerRow::factory()->forUser(User::factory()->create())->create()->id,
        'price_cents' => 12300,
        'stock' => 3,
    ]);
    $buyer = User::factory()->create();

    expect(Artisan::all()['orders:race-test:buyer']->isHidden())->toBeTrue();

    $exit = Artisan::call('orders:race-test:buyer', ['userId' => $buyer->id, 'offerId' => $offer->id]);
    $result = json_decode(trim(Artisan::output()), true);

    expect($exit)->toBe(0)
        ->and($result['result'])->toBe('placed')
        ->and($result['ms'])->toBeFloat()
        ->and($result['orders'])->toHaveCount(1)
        ->and(DB::table('orders')->where('id', $result['orders'][0])->value('buyer_user_id'))->toBe($buyer->id);
});

test('komenda kupującego zgłasza odmowę złożenia jako wynik, nie błąd', function () {
    $offer = OfferRow::factory()->soldOut()->create([
        'seller_id' => SellerRow::factory()->forUser(User::factory()->create())->create()->id,
    ]);

    $exit = Artisan::call('orders:race-test:buyer', ['userId' => User::factory()->create()->id, 'offerId' => $offer->id]);
    $result = json_decode(trim(Artisan::output()), true);

    expect($exit)->toBe(0)
        ->and($result['result'])->toBe('refused')
        ->and($result['reason'])->toBe('offer_no_longer_available');
});

test('konflikt wersji oferty trafia do kanału logu concurrency', function () {
    $path = raceConflictLog();
    $row = OfferRow::factory()->create([
        'seller_id' => SellerRow::factory()->forUser(User::factory()->create())->create()->id,
    ]);
    $repo = app(OfferRepository::class);
    $offer = $repo->get(OfferId::fromInt($row->id));
    $repo->save($offer);

    expect(fn () => $repo->save($offer))->toThrow(ConcurrencyConflict::class)
        ->and(file_get_contents($path))->toContain('concurrency_conflict')->toContain('"aggregate":"offer"');
});

test('konflikt wersji zamówienia trafia do kanału logu concurrency', function () {
    $path = raceConflictLog();
    $offer = OfferRow::factory()->create([
        'seller_id' => SellerRow::factory()->forUser(User::factory()->create())->create()->id,
        'price_cents' => 1000,
        'stock' => 2,
    ]);
    Artisan::call('orders:race-test:buyer', ['userId' => User::factory()->create()->id, 'offerId' => $offer->id]);
    $orderId = json_decode(trim(Artisan::output()), true)['orders'][0];

    $repo = app(OrderRepository::class);
    $order = $repo->get(OrderId::fromInt($orderId));
    $repo->save($order);

    expect(fn () => $repo->save($order))->toThrow(ConcurrencyConflict::class)
        ->and(file_get_contents($path))->toContain('concurrency_conflict')->toContain('"aggregate":"order"');
});
