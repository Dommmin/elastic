<?php

use App\FakePaymentGateway\FakePaymentGateway;
use App\Models\CartItem;
use App\Models\Offer as OfferRow;
use App\Models\Seller as SellerRow;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Sprzedawca z ofertą i (opcjonalnie) opłaconym zamówieniem kupującego.
 * Checkout i webhook idą prawdziwą ścieżką (HTTP), jak u kupującego.
 *
 * @return array{0: User, 1: SellerRow, 2: OfferRow}
 */
function sellerWithOffer(int $stock = 5): array
{
    $user = User::factory()->create();
    $seller = SellerRow::factory()->forUser($user)->create();
    $offer = OfferRow::factory()->create(['seller_id' => $seller->id, 'price_cents' => 10000, 'stock' => $stock]);

    return [$user, $seller, $offer];
}

function checkoutOrder(OfferRow $offer, bool $pay): int
{
    $buyer = User::factory()->create();
    CartItem::create(['user_id' => $buyer->id, 'offer_id' => $offer->id, 'quantity' => 2, 'added_price_cents' => 10000]);

    test()->actingAs($buyer)->post(route('cart.checkout'), ['lines' => [
        ['offer_id' => $offer->id, 'quantity' => 2, 'unit_price_cents' => 10000],
    ]])->assertSessionHasNoErrors();

    $id = (int) DB::table('orders')->where('buyer_user_id', $buyer->id)->max('id');

    if ($pay) {
        // Fake tylko na czas płatności (bez opóźnionego webhooka); kolejne checkouty znów biegną synchronicznie.
        $realQueue = app('queue');
        Queue::fake();
        test()->actingAs($buyer)->post(route('orders.pay', $id));
        $payment = (string) DB::table('fake_gateway_payments')->where('reference', 'order-'.$id)->value('id');
        $gateway = app(FakePaymentGateway::class);
        $gateway->decide($payment, 'pay');
        $signed = $gateway->signedWebhook($payment);
        test()->call('POST', route('webhooks.payments'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE' => $signed['signature'],
        ], $signed['body'])->assertOk();
        Queue::swap($realQueue);
    }

    return $id;
}

beforeEach(function () {
    config(['services.fake_gateway.webhook_secret' => 'test-secret']);
});

test('„Do wysłania” pokazuje tylko opłacone zamówienia tego sprzedawcy', function () {
    [$user, , $offer] = sellerWithOffer();
    $paid = checkoutOrder($offer, pay: true);
    checkoutOrder($offer, pay: false); // czeka na płatność
    [, , $foreignOffer] = sellerWithOffer();
    checkoutOrder($foreignOffer, pay: true);

    $this->actingAs($user)->get(route('seller.orders.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('seller/orders/Index')
            ->where('tab', 'to_ship')
            ->has('orders', 1)
            ->where('orders.0.id', $paid)
            ->where('orders.0.status', 'paid'));

    $this->actingAs($user)->get(route('seller.orders.index', ['tab' => 'all']))
        ->assertInertia(fn (Assert $page) => $page->where('tab', 'all')->has('orders', 2));
});

test('wysyłka z numerem przesyłki → Wysłane', function () {
    [$user, , $offer] = sellerWithOffer();
    $id = checkoutOrder($offer, pay: true);

    $this->actingAs($user)->get(route('seller.orders.show', $id))
        ->assertInertia(fn (Assert $page) => $page
            ->component('seller/orders/Show')
            ->where('actions', ['ship', 'cancel_by_seller']));

    $this->actingAs($user)->post(route('seller.orders.ship', $id), ['tracking_number' => '  PL123456  '])
        ->assertRedirect(route('seller.orders.show', $id))
        ->assertSessionHasNoErrors();

    expect(DB::table('orders')->where('id', $id)->value('status'))->toBe('shipped')
        ->and(DB::table('orders')->where('id', $id)->value('tracking_number'))->toBe('PL123456');

    $this->actingAs($user)->get(route('seller.orders.show', $id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('order.status_label', 'Wysłane')
            ->where('order.tracking_number', 'PL123456')
            ->where('actions', []));
});

test('numer przesyłki ze spacji, pusty albo za długi → błąd pola, zamówienie Opłacone', function (mixed $value) {
    [$user, , $offer] = sellerWithOffer();
    $id = checkoutOrder($offer, pay: true);

    $this->actingAs($user)->from(route('seller.orders.show', $id))
        ->post(route('seller.orders.ship', $id), ['tracking_number' => $value])
        ->assertRedirect(route('seller.orders.show', $id))
        ->assertSessionHasErrors('tracking_number');

    expect(DB::table('orders')->where('id', $id)->value('status'))->toBe('paid');
})->with([
    'spacje' => ['     '],
    'pusty' => [''],
    'brak' => [null],
    'za krótki' => ['ab'],
    'za długi' => [str_repeat('x', 65)],
]);

test('anulowanie opłaconego zleca zwrot i nie przywraca stanu', function () {
    [$user, , $offer] = sellerWithOffer(stock: 5);
    $id = checkoutOrder($offer, pay: true);
    $stockBefore = OfferRow::find($offer->id)->stock;

    $this->actingAs($user)->post(route('seller.orders.cancel', $id), ['reason' => 'Brak towaru w magazynie'])
        ->assertRedirect(route('seller.orders.show', $id))
        ->assertSessionHasNoErrors();

    expect(DB::table('orders')->where('id', $id)->value('status'))->toBe('cancelled')
        ->and(DB::table('order_refunds')->where('order_id', $id)->count())->toBe(1)
        ->and((int) DB::table('order_refunds')->where('order_id', $id)->value('amount_cents'))->toBe(20000)
        ->and(OfferRow::find($offer->id)->stock)->toBe($stockBefore)
        ->and(session('inertia.flash_data.toast.message'))->toBe('Zamówienie anulowane, zwrot zlecony');

    $this->actingAs($user)->get(route('seller.orders.show', $id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('order.cancellation_label', 'Anulowałeś zamówienie: Brak towaru w magazynie')
            ->where('actions', []));
});

test('powód anulowania pusty lub za długi → błąd pola', function () {
    [$user, , $offer] = sellerWithOffer();
    $id = checkoutOrder($offer, pay: true);

    foreach (['   ', str_repeat('x', 501)] as $reason) {
        $this->actingAs($user)->from(route('seller.orders.show', $id))
            ->post(route('seller.orders.cancel', $id), ['reason' => $reason])
            ->assertSessionHasErrors('reason');
    }

    expect(DB::table('orders')->where('id', $id)->value('status'))->toBe('paid');
});

test('cudze zamówienie → 404', function () {
    [, , $offer] = sellerWithOffer();
    $id = checkoutOrder($offer, pay: true);
    [$stranger] = sellerWithOffer();

    $this->actingAs($stranger)->get(route('seller.orders.show', $id))->assertNotFound();
    $this->actingAs($stranger)->post(route('seller.orders.ship', $id), ['tracking_number' => 'PL123'])->assertNotFound();
    $this->actingAs($stranger)->post(route('seller.orders.cancel', $id), ['reason' => 'x'])->assertNotFound();
    expect(DB::table('orders')->where('id', $id)->value('status'))->toBe('paid');
});

test('pulpit pokazuje licznik do wysłania', function () {
    [$user, , $offer] = sellerWithOffer();
    checkoutOrder($offer, pay: true);
    checkoutOrder($offer, pay: false);

    $this->actingAs($user)->get(route('seller.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('stats.orders_to_ship', 1));
});
