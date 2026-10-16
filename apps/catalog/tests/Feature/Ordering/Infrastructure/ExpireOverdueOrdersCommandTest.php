<?php

use App\Models\Offer as OfferRow;
use App\Models\Seller as SellerRow;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Ordering\Application\Order\CartLine;
use Ordering\Application\Order\PlaceOrdersFromCart;
use Ordering\Application\Order\PlaceOrdersFromCartHandler;
use Ordering\Application\Port\BuyerCart;
use Ordering\Domain\BuyerId;

beforeEach(function () {
    // Saga wstrzymana: zamówienie zostaje `Placed`, a nas interesuje tylko sweep.
    config(['queue.ordering_process_connection' => 'database']);
    app()->instance(BuyerCart::class, new class implements BuyerCart
    {
        public function clear(BuyerId $buyerId): void {}
    });
});

function sweepPlaceOrder(): int
{
    $offer = OfferRow::factory()->create([
        'seller_id' => SellerRow::factory()->forUser(User::factory()->create())->create()->id,
        'price_cents' => 10000,
        'stock' => 5,
    ]);

    return app(PlaceOrdersFromCartHandler::class)->handle(
        new PlaceOrdersFromCart(User::factory()->create()->id, [new CartLine($offer->id, 1, 10000)])
    )[0]->value();
}

test('orders:expire-overdue szanuje karencję i zapisuje czas przebiegu', function () {
    $this->travelTo(now()->startOfSecond());
    $orderId = sweepPlaceOrder();

    $this->travel(16)->minutes(); // po terminie (15 min), w karencji (5 min)
    $this->artisan('orders:expire-overdue')->expectsOutputToContain('0')->assertSuccessful();
    expect(DB::table('orders')->where('id', $orderId)->value('status'))->toBe('placed');

    $this->travel(5)->minutes(); // 21 min: po karencji
    $this->artisan('orders:expire-overdue')->expectsOutputToContain('1')->assertSuccessful();

    expect(DB::table('orders')->where('id', $orderId)->value('status'))->toBe('expired')
        ->and(Cache::get('ordering:sweep:last_run_at'))->toBe(now()->toIso8601String());
});

test('wygaśnięcie kolejkuje zwolnienie rezerwacji', function () {
    $this->travelTo(now()->startOfSecond());
    sweepPlaceOrder();
    DB::table('jobs')->delete();

    $this->travel(21)->minutes();
    $this->artisan('orders:expire-overdue')->assertSuccessful();

    expect(DB::table('jobs')->count())->toBe(1);
});

test('przebieg jest zaplanowany co minutę bez nakładania', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command, 'orders:expire-overdue'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
