<?php

use App\FakePaymentGateway\FakePaymentGateway;
use App\Models\CartItem;
use App\Models\Offer as OfferRow;
use App\Models\Seller as SellerRow;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Ordering\Application\Order\ExpireOverdueOrders;
use Ordering\Application\Order\ExpireOverdueOrdersHandler;

/**
 * Składa zamówienie przez prawdziwy checkout (saga biegnie synchronicznie, R5)
 * i zwraca jego ID. Jedna oferta, ilość 2.
 */
function buyerOrder(User $buyer, int $stock = 5, ?SellerRow $seller = null): array
{
    $offer = OfferRow::factory()->create([
        'seller_id' => ($seller ?? SellerRow::factory()->forUser(User::factory()->create())->create())->id,
        'price_cents' => 10000,
        'stock' => $stock,
    ]);
    CartItem::create(['user_id' => $buyer->id, 'offer_id' => $offer->id, 'quantity' => 2, 'added_price_cents' => 10000]);

    test()->actingAs($buyer)->post(route('cart.checkout'), ['lines' => [
        ['offer_id' => $offer->id, 'quantity' => 2, 'unit_price_cents' => 10000],
    ]])->assertSessionHasNoErrors();

    return [(int) DB::table('orders')->where('buyer_user_id', $buyer->id)->max('id'), $offer];
}

function paymentIdFor(int $orderId): string
{
    return (string) DB::table('fake_gateway_payments')->where('reference', 'order-'.$orderId)->value('id');
}

function postBuyerWebhook(array $signed): void
{
    test()->call('POST', route('webhooks.payments'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SIGNATURE' => $signed['signature'],
    ], $signed['body'])->assertOk();
}

beforeEach(function () {
    config(['services.fake_gateway.webhook_secret' => 'test-secret']);
});

test('lista pokazuje tylko własne zamówienia kupującego', function () {
    $buyer = User::factory()->create();
    $other = User::factory()->create();
    [$mine] = buyerOrder($buyer);
    buyerOrder($other);

    $this->actingAs($buyer)->get(route('orders.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('shop/orders/Index')
            ->has('orders', 1)
            ->where('orders.0.id', $mine)
            ->where('orders.0.status', 'awaiting_payment')
            ->where('orders.0.total_cents', 20000));
});

test('gość nie widzi zamówień', function () {
    $this->get(route('orders.index'))->assertRedirect(route('login'));
});

test('cudze zamówienie → 404', function () {
    [$id] = buyerOrder(User::factory()->create());
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get(route('orders.show', $id))->assertNotFound();
    $this->actingAs($stranger)->post(route('orders.pay', $id))->assertNotFound();
    $this->actingAs($stranger)->post(route('orders.cancel', $id))->assertNotFound();
});

test('pełna ścieżka: checkout → zapłać → webhook → Opłacone', function () {
    $buyer = User::factory()->create();
    [$id] = buyerOrder($buyer);
    Queue::fake();

    $response = $this->actingAs($buyer)->post(route('orders.pay', $id), [], ['X-Inertia' => 'true']);
    $response->assertStatus(409);
    $payment = paymentIdFor($id);
    expect($response->headers->get('X-Inertia-Location'))->toBe(route('fake-gateway.show', $payment));

    $gateway = app(FakePaymentGateway::class);
    expect($gateway->find($payment)->return_url)->toBe(route('orders.show', [$id, 'payment' => 'returned']));

    $gateway->decide($payment, 'pay');
    postBuyerWebhook($gateway->signedWebhook($payment));

    $this->actingAs($buyer)->get(route('orders.show', $id))
        ->assertInertia(fn (Assert $page) => $page
            ->component('shop/orders/Show')
            ->where('order.status', 'paid')
            ->where('order.status_label', 'Opłacone')
            ->where('actions', [])
            ->where('timeline', fn ($t) => collect($t)->pluck('type')->contains('order.paid')));
});

test('powrót z bramki przed webhookiem: awaiting_confirmation i brak akcji pay', function () {
    $buyer = User::factory()->create();
    [$id] = buyerOrder($buyer);

    $this->actingAs($buyer)->get(route('orders.show', [$id, 'payment' => 'returned']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('awaiting_confirmation', true)
            ->where('order.status', 'awaiting_payment')
            ->where('actions', ['cancel']));

    $this->actingAs($buyer)->get(route('orders.show', $id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('awaiting_confirmation', false)
            ->where('actions', ['pay', 'cancel']));
});

test('seconds_to_deadline liczone na serwerze', function () {
    $buyer = User::factory()->create();
    $this->freezeTime();
    [$id] = buyerOrder($buyer);

    $this->actingAs($buyer)->get(route('orders.show', $id))
        ->assertInertia(fn (Assert $page) => $page->where('order.seconds_to_deadline', 900));

    $this->travel(10)->minutes();

    $this->actingAs($buyer)->get(route('orders.show', $id))
        ->assertInertia(fn (Assert $page) => $page->where('order.seconds_to_deadline', 300));

    $this->travel(20)->minutes();

    $this->actingAs($buyer)->get(route('orders.show', $id))
        ->assertInertia(fn (Assert $page) => $page->where('order.seconds_to_deadline', 0));
});

test('anulowanie przed płatnością zwalnia rezerwacje', function () {
    $buyer = User::factory()->create();
    [$id, $offer] = buyerOrder($buyer);
    $availableBefore = OfferRow::query()->withAvailable()->find($offer->id)->available;

    $this->actingAs($buyer)->post(route('orders.cancel', $id))
        ->assertRedirect(route('orders.show', $id))
        ->assertSessionHasNoErrors();

    expect(DB::table('orders')->where('id', $id)->value('status'))->toBe('cancelled')
        ->and($availableBefore)->toBe(3)
        ->and(OfferRow::query()->withAvailable()->find($offer->id)->available)->toBe(5);

    $this->actingAs($buyer)->get(route('orders.show', $id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('order.cancellation_label', 'Anulowałeś zamówienie')
            ->where('actions', []));
});

test('anulowanie zamówienia, którego nie wolno anulować, wraca z błędem', function () {
    $buyer = User::factory()->create();
    [$id] = buyerOrder($buyer);
    $this->actingAs($buyer)->post(route('orders.cancel', $id));

    $this->actingAs($buyer)->from(route('orders.show', $id))->post(route('orders.cancel', $id))
        ->assertSessionHasErrors(['domain' => 'Tej operacji nie można wykonać na zamówieniu w obecnym stanie']);
});

test('odrzucone zamówienie pokazuje powód z nazwą produktu', function () {
    $buyer = User::factory()->create();
    [$id] = buyerOrder($buyer, stock: 1);
    $name = DB::table('order_lines')->where('order_id', $id)->value('product_name');

    $this->actingAs($buyer)->get(route('orders.show', $id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('order.status', 'rejected')
            ->where('order.status_label', 'Odrzucone')
            ->where('order.rejection_label', 'Zabrakło towaru: '.$name)
            ->where('actions', []));
});

test('wpłata po terminie pokazuje zwrot', function () {
    $buyer = User::factory()->create();
    [$id] = buyerOrder($buyer);
    Queue::fake();
    $this->actingAs($buyer)->post(route('orders.pay', $id));
    $payment = paymentIdFor($id);
    $gateway = app(FakePaymentGateway::class);

    $this->travel(21)->minutes();
    expect(app(ExpireOverdueOrdersHandler::class)->handle(new ExpireOverdueOrders))->toBe(1);
    $gateway->decide($payment, 'pay_expired');
    postBuyerWebhook($gateway->signedWebhook($payment));

    $this->actingAs($buyer)->get(route('orders.show', $id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('order.status', 'expired')
            ->where('order.refunds', fn ($refunds) => count($refunds) === 1
                && $refunds[0]['amount_cents'] === 20000
                && $refunds[0]['status'] === 'requested'));
});
