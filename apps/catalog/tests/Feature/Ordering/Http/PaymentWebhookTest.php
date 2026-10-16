<?php

use App\FakePaymentGateway\FakePaymentGateway;
use App\Models\Offer as OfferRow;
use App\Models\Seller as SellerRow;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Ordering\Application\Order\ExpireOverdueOrders;
use Ordering\Application\Order\ExpireOverdueOrdersHandler;
use Ordering\Domain\BuyerId;
use Ordering\Domain\LineQuantity;
use Ordering\Domain\OfferId;
use Ordering\Domain\Order;
use Ordering\Domain\OrderId;
use Ordering\Domain\OrderLine;
use Ordering\Domain\OrderRepository;
use Ordering\Domain\OrderStatus;
use Ordering\Domain\ProductName;
use Ordering\Domain\RefundReason;
use Ordering\Domain\SellerId;
use Ordering\Infrastructure\Job\RefundPaymentJob;
use SharedKernel\Money;

/**
 * Zamówienie w `AwaitingPayment` (2 × 1999 + 500 = 4498 gr) zapisane w bazie.
 */
function webhookAwaitingOrder(): Order
{
    $repo = app(OrderRepository::class);
    $seller = SellerRow::factory()->create();
    $offers = OfferRow::factory()->count(2)->create(['seller_id' => $seller->id]);
    $now = new DateTimeImmutable('now');

    $order = Order::place(
        $repo->nextIdentity(),
        BuyerId::fromInt(User::factory()->create()->id),
        SellerId::fromInt($seller->id),
        [
            OrderLine::create(OfferId::fromInt($offers[0]->id), ProductName::fromString('Pierwszy'), Money::fromCents(1999), LineQuantity::fromInt(2)),
            OrderLine::create(OfferId::fromInt($offers[1]->id), ProductName::fromString('Drugi'), Money::fromCents(500), LineQuantity::fromInt(1)),
        ],
        $now,
    );
    foreach ($order->lines() as $line) {
        $order->markLineReserved($line->offerId(), $now);
    }
    $repo->save($order);

    return $repo->get($order->id());
}

/**
 * Podpisany webhook zbudowany przez samą bramkę (`signedWebhook`), jak w produkcji.
 *
 * @return array{body: string, signature: string}
 */
function webhookFor(Order $order, string $decision = 'pay'): array
{
    Queue::fake();
    $gateway = app(FakePaymentGateway::class);
    $payment = $gateway->createPayment('order-'.$order->id()->value(), $order->total()->cents(), 'PLN', new DateTimeImmutable('+15 minutes'), 'http://localhost/');
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

function webhookOrderState(OrderId $id): Order
{
    return app(OrderRepository::class)->get($id);
}

beforeEach(function () {
    config(['services.fake_gateway.webhook_secret' => 'test-secret']);
});

test('webhook ze złym podpisem → 401, zamówienie bez zmian', function () {
    $order = webhookAwaitingOrder();
    $signed = webhookFor($order);

    postWebhook($signed, 'zly-podpis')->assertUnauthorized();
    postWebhook($signed, '')->assertUnauthorized();

    expect(webhookOrderState($order->id())->status())->toBe(OrderStatus::AwaitingPayment);
});

test('webhook succeeded → zamówienie Paid', function () {
    $order = webhookAwaitingOrder();

    postWebhook(webhookFor($order))->assertOk();

    expect(webhookOrderState($order->id())->status())->toBe(OrderStatus::Paid)
        ->and(webhookOrderState($order->id())->payment())->not->toBeNull();
});

test('webhook failed → status bez zmian (H7), 200', function () {
    $order = webhookAwaitingOrder();

    postWebhook(webhookFor($order, 'decline'))->assertOk();

    expect(webhookOrderState($order->id())->status())->toBe(OrderStatus::AwaitingPayment);
});

test('zdublowany webhook → 200, jedna wpłata, żadnego zwrotu', function () {
    $order = webhookAwaitingOrder();
    $signed = webhookFor($order);

    postWebhook($signed)->assertOk();
    postWebhook($signed)->assertOk();

    $after = webhookOrderState($order->id());
    expect($after->status())->toBe(OrderStatus::Paid)
        ->and($after->refunds())->toBe([]);
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
    expect(webhookOrderState($order->id())->status())->toBe(OrderStatus::AwaitingPayment);
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
    config(['queue.ordering_process_connection' => 'database']);
    $order = webhookAwaitingOrder();
    $gateway = app(FakePaymentGateway::class);
    Queue::fake();
    $payment = $gateway->createPayment('order-'.$order->id()->value(), $order->total()->cents(), 'PLN', new DateTimeImmutable('+15 minutes'), 'http://localhost/');

    $this->travel(21)->minutes();
    expect(app(ExpireOverdueOrdersHandler::class)->handle(new ExpireOverdueOrders))->toBe(1);
    expect(webhookOrderState($order->id())->status())->toBe(OrderStatus::Expired);

    $gateway->decide($payment['payment_id'], 'pay_expired');
    Queue::fake();

    postWebhook($gateway->signedWebhook($payment['payment_id']))->assertOk();

    $after = webhookOrderState($order->id());
    expect($after->status())->toBe(OrderStatus::Expired)
        ->and($after->payment())->toBeNull()
        ->and($after->refunds())->toHaveCount(1)
        ->and($after->refunds()[0]->reason)->toBe(RefundReason::LatePayment)
        ->and($after->refunds()[0]->amount->cents())->toBe(4498);
    Queue::assertPushed(RefundPaymentJob::class, 1);
});
