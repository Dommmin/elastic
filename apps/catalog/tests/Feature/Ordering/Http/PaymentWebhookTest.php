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
 * Ten plik jest wspólny dla gałęzi `zamowienia` (saga) i `zamowienia-ddd-lite`
 * (jedna transakcja), bajt w bajt. Dlatego stan budujemy prawdziwym
 * checkoutem, sweep wołamy komendą, a sprawdzamy kolumny w bazie: test,
 * który sięga po agregat albo repozytorium, sprawdzałby też architekturę
 * i padłby na drugiej gałęzi, choć zachowanie jest to samo
 * (docs/blog/ddd-vs-ddd-lite.md, rozdział 1).
 */

/**
 * Zamówienie w `awaiting_payment` (2 × 1999 + 500 = 4498 gr) złożone przez checkout.
 */
function webhookAwaitingOrder(): int
{
    $seller = SellerRow::factory()->forUser(User::factory()->create())->create();
    $first = OfferRow::factory()->create(['seller_id' => $seller->id, 'price_cents' => 1999, 'stock' => 5]);
    $second = OfferRow::factory()->create(['seller_id' => $seller->id, 'price_cents' => 500, 'stock' => 5]);
    $buyer = User::factory()->create();
    CartItem::create(['user_id' => $buyer->id, 'offer_id' => $first->id, 'quantity' => 2, 'added_price_cents' => 1999]);
    CartItem::create(['user_id' => $buyer->id, 'offer_id' => $second->id, 'quantity' => 1, 'added_price_cents' => 500]);

    test()->actingAs($buyer)->post(route('cart.checkout'), ['lines' => [
        ['offer_id' => $first->id, 'quantity' => 2, 'unit_price_cents' => 1999],
        ['offer_id' => $second->id, 'quantity' => 1, 'unit_price_cents' => 500],
    ]])->assertSessionHasNoErrors();

    $id = (int) DB::table('orders')->where('buyer_user_id', $buyer->id)->value('id');
    expect(webhookOrderStatus($id))->toBe('awaiting_payment')
        ->and((int) DB::table('orders')->where('id', $id)->value('total_cents'))->toBe(4498);

    return $id;
}

/**
 * Podpisany webhook zbudowany przez samą bramkę (`signedWebhook`), jak w produkcji.
 *
 * @return array{body: string, signature: string}
 */
function webhookFor(int $orderId, string $decision = 'pay'): array
{
    Queue::fake();
    $gateway = app(FakePaymentGateway::class);
    $payment = $gateway->createPayment('order-'.$orderId, 4498, 'PLN', new DateTimeImmutable('+15 minutes'), 'http://localhost/');
    $gateway->decide($payment['payment_id'], $decision);

    return $gateway->signedWebhook($payment['payment_id']);
}

function postWebhook(array $signed, ?string $signature = null)
{
    return test()->call('POST', route('webhooks.payments'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SIGNATURE' => $signature ?? $signed['signature'],
    ], $signed['body']);
}

function webhookOrderStatus(int $orderId): string
{
    return (string) DB::table('orders')->where('id', $orderId)->value('status');
}

beforeEach(function () {
    config(['services.fake_gateway.webhook_secret' => 'test-secret']);
});

test('webhook ze złym podpisem → 401, zamówienie bez zmian', function () {
    $order = webhookAwaitingOrder();
    $signed = webhookFor($order);

    postWebhook($signed, 'zly-podpis')->assertUnauthorized();
    postWebhook($signed, '')->assertUnauthorized();

    expect(webhookOrderStatus($order))->toBe('awaiting_payment');
});

test('webhook succeeded → zamówienie Paid', function () {
    $order = webhookAwaitingOrder();

    postWebhook(webhookFor($order))->assertOk();

    expect(webhookOrderStatus($order))->toBe('paid')
        ->and(DB::table('orders')->where('id', $order)->value('payment_id'))->not->toBeNull();
});

test('webhook failed → status bez zmian (H7), 200', function () {
    $order = webhookAwaitingOrder();

    postWebhook(webhookFor($order, 'decline'))->assertOk();

    expect(webhookOrderStatus($order))->toBe('awaiting_payment');
});

test('zdublowany webhook → 200, jedna wpłata, żadnego zwrotu', function () {
    $order = webhookAwaitingOrder();
    $signed = webhookFor($order);

    postWebhook($signed)->assertOk();
    postWebhook($signed)->assertOk();

    expect(webhookOrderStatus($order))->toBe('paid')
        ->and(DB::table('order_refunds')->where('order_id', $order)->count())->toBe(0);
});

test('webhook dla nieznanego zamówienia → 200 i ostrzeżenie w logu', function () {
    Log::spy();
    $signed = webhookFor(webhookAwaitingOrder());
    $body = json_decode($signed['body'], true);
    $body['reference'] = 'order-987654';
    $raw = json_encode($body);

    postWebhook(['body' => $raw, 'signature' => hash_hmac('sha256', $raw, 'test-secret')])->assertOk();

    Log::shouldHaveReceived('warning')->once();
});

test('webhook z nieznanym formatem reference → 200 i ostrzeżenie', function () {
    Log::spy();
    $signed = webhookFor(webhookAwaitingOrder());
    $body = json_decode($signed['body'], true);
    $body['reference'] = 'cos-innego-42';
    $raw = json_encode($body);

    postWebhook(['body' => $raw, 'signature' => hash_hmac('sha256', $raw, 'test-secret')])->assertOk();

    Log::shouldHaveReceived('warning')->once();
});

test('poprawny podpis, ale zepsuty payload → 200, ostrzeżenie, zamówienie bez zmian', function (string $field, mixed $value) {
    Log::spy();
    $order = webhookAwaitingOrder();
    $body = json_decode(webhookFor($order)['body'], true);
    if ($value === null) {
        unset($body[$field]);
    } else {
        $body[$field] = $value;
    }
    $raw = json_encode($body);

    postWebhook(['body' => $raw, 'signature' => hash_hmac('sha256', $raw, 'test-secret')])->assertOk();

    Log::shouldHaveReceived('warning')->once();
    expect(webhookOrderStatus($order))->toBe('awaiting_payment');
})->with([
    'waluta spoza listy' => ['currency', 'XXX'],
    'brak waluty' => ['currency', null],
    'kwota tekstem' => ['amount_cents', 'dużo'],
    'ujemna kwota' => ['amount_cents', -5],
    'nieznany status' => ['status', 'refunded'],
    'pusty payment_id' => ['payment_id', ''],
    'za długi payment_id' => ['payment_id', str_repeat('x', 65)],
]);

test('poprawny podpis, ale body nie jest JSON-em → 200 i ostrzeżenie', function () {
    Log::spy();
    $raw = 'to nie json';

    postWebhook(['body' => $raw, 'signature' => hash_hmac('sha256', $raw, 'test-secret')])->assertOk();

    Log::shouldHaveReceived('warning')->once();
});

test('„zapłać mimo wygaśnięcia” (H6): wpłata po terminie przez webhook daje zwrot, status zostaje Expired', function () {
    $order = webhookAwaitingOrder();
    config(['queue.ordering_process_connection' => 'database']);
    $gateway = app(FakePaymentGateway::class);
    Queue::fake();
    $payment = $gateway->createPayment('order-'.$order, 4498, 'PLN', new DateTimeImmutable('+15 minutes'), 'http://localhost/');

    $this->travel(21)->minutes();
    $this->artisan('orders:expire-overdue')->expectsOutput('Wygaszono zamówień: 1')->assertSuccessful();
    expect(webhookOrderStatus($order))->toBe('expired');

    $gateway->decide($payment['payment_id'], 'pay_expired');
    Queue::fake();

    postWebhook($gateway->signedWebhook($payment['payment_id']))->assertOk();

    $refunds = DB::table('order_refunds')->where('order_id', $order)->get();
    expect(webhookOrderStatus($order))->toBe('expired')
        ->and(DB::table('orders')->where('id', $order)->value('payment_id'))->toBeNull()
        ->and($refunds)->toHaveCount(1)
        ->and($refunds[0]->reason)->toBe('late_payment')
        ->and((int) $refunds[0]->amount_cents)->toBe(4498);

    // Zwrot to wywołanie bramki, więc na obu gałęziach idzie jobem (jedynym po webhooku).
    $pushed = Queue::pushedJobs();
    expect(array_map(class_basename(...), array_keys($pushed)))->toBe(['RefundPaymentJob'])
        ->and(array_values($pushed)[0])->toHaveCount(1);
});
