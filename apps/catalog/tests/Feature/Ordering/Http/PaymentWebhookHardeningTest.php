<?php

use App\FakePaymentGateway\FakePaymentGateway;
use App\Models\CartItem;
use App\Models\Offer as OfferRow;
use App\Models\Seller as SellerRow;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/*
 * Utwardzenie webhooka po końcowym przeglądzie gałęzi. Osobny plik, bo
 * PaymentWebhookTest.php jest wspólny z gałęzią DDD-lite bajt w bajt.
 * Tak jak tam: stan przez checkout, sprawdzenia na kolumnach w bazie.
 */

/**
 * @return array{0: int, 1: array<string, mixed>} ID zamówienia w `awaiting_payment` i payload wpłaty z bramki
 */
function hardenedWebhookOrder(): array
{
    $seller = SellerRow::factory()->forUser(User::factory()->create())->create();
    $offer = OfferRow::factory()->create(['seller_id' => $seller->id, 'price_cents' => 1000, 'stock' => 5]);
    $buyer = User::factory()->create();
    CartItem::create(['user_id' => $buyer->id, 'offer_id' => $offer->id, 'quantity' => 1, 'added_price_cents' => 1000]);

    test()->actingAs($buyer)->post(route('cart.checkout'), ['lines' => [
        ['offer_id' => $offer->id, 'quantity' => 1, 'unit_price_cents' => 1000],
    ]])->assertSessionHasNoErrors();

    $id = (int) DB::table('orders')->where('buyer_user_id', $buyer->id)->value('id');
    expect(DB::table('orders')->where('id', $id)->value('status'))->toBe('awaiting_payment');

    Queue::fake();
    $gateway = app(FakePaymentGateway::class);
    $payment = $gateway->createPayment('order-'.$id, 1000, 'PLN', new DateTimeImmutable('+15 minutes'), 'http://localhost/');
    $gateway->decide($payment['payment_id'], 'pay');

    return [$id, json_decode($gateway->signedWebhook($payment['payment_id'])['body'], true)];
}

/**
 * @param  array<string, mixed>  $payload
 */
function postHardenedWebhook(array $payload, string $secret)
{
    $raw = json_encode($payload);

    return test()->call('POST', route('webhooks.payments'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SIGNATURE' => hash_hmac('sha256', $raw, $secret),
    ], $raw);
}

test('pusty sekret webhooka → 500 i błąd w logu, nawet z podpisem pustym kluczem; zamówienie bez zmian', function () {
    config(['services.fake_gateway.webhook_secret' => 'test-secret']);
    [$order, $payload] = hardenedWebhookOrder();
    config(['services.fake_gateway.webhook_secret' => '']);
    Log::spy();

    postHardenedWebhook($payload, '')->assertStatus(500);

    Log::shouldHaveReceived('error')->once();
    expect(DB::table('orders')->where('id', $order)->value('status'))->toBe('awaiting_payment');
});

test('kwota 0 to zepsuty payload → 200, ostrzeżenie, zamówienie bez zmian', function () {
    config(['services.fake_gateway.webhook_secret' => 'test-secret']);
    [$order, $payload] = hardenedWebhookOrder();
    Log::spy();

    postHardenedWebhook([...$payload, 'amount_cents' => 0], 'test-secret')->assertOk();

    Log::shouldHaveReceived('warning')->once();
    expect(DB::table('orders')->where('id', $order)->value('status'))->toBe('awaiting_payment')
        ->and(DB::table('order_refunds')->where('order_id', $order)->count())->toBe(0);
});
