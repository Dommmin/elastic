<?php

use App\Models\Offer as OfferRow;
use App\Models\Product;
use App\Models\Seller as SellerRow;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Ordering\Application\Order\CancelOrderByBuyerHandler;
use Ordering\Application\Order\CancelOrderBySellerHandler;
use Ordering\Application\Order\ExpireOverdueOrdersHandler;
use Ordering\Application\Order\RecordPaymentFailureHandler;
use Ordering\Application\Order\RecordPaymentHandler;
use Ordering\Application\Order\ShipOrderHandler;
use Ordering\Application\Order\StartPaymentHandler;
use Ordering\Application\Port\Clock;
use Ordering\Application\Port\DomainEventDispatcher;
use Ordering\Application\Port\OfferSnapshots;
use Ordering\Application\Port\PaymentGateway;
use Ordering\Application\Port\PaymentGatewayUnavailable;
use Ordering\Application\Port\ProcessJobQueue;
use Ordering\Application\Port\SellerAccounts;
use Ordering\Application\Port\StockReservations;
use Ordering\Application\Port\TransactionManager;
use Ordering\Application\Process\ConfirmOrderReservations;
use Ordering\Application\Process\OrderProcessManager;
use Ordering\Application\Process\RefundPayment;
use Ordering\Application\Process\ReleaseOrderReservations;
use Ordering\Application\Process\ReserveOrderLines;
use Ordering\Domain\Event\OrderCancelled;
use Ordering\Domain\Event\OrderExpired;
use Ordering\Domain\Event\OrderPaid;
use Ordering\Domain\Event\OrderPlaced;
use Ordering\Domain\Event\OrderRejected;
use Ordering\Domain\Event\RefundRequested;
use Ordering\Domain\OfferId;
use Ordering\Domain\OrderId;
use Ordering\Domain\OrderRepository;
use Ordering\Domain\PaymentId;
use Ordering\Domain\RefundId;
use Ordering\Infrastructure\FakeGatewayPaymentGateway;
use Ordering\Infrastructure\InProcessDomainEventDispatcher;
use Ordering\Infrastructure\Job\RefundPaymentJob;
use SharedKernel\Money;

test('provider rozwiązuje wszystkie porty i handlery', function (string $abstract) {
    expect(app($abstract))->toBeObject();
})->with([
    Clock::class, TransactionManager::class, DomainEventDispatcher::class, OrderRepository::class,
    StockReservations::class, OfferSnapshots::class, SellerAccounts::class, ProcessJobQueue::class,
    ReserveOrderLines::class, ReleaseOrderReservations::class, ConfirmOrderReservations::class,
    ExpireOverdueOrdersHandler::class, RecordPaymentHandler::class, RecordPaymentFailureHandler::class,
    CancelOrderByBuyerHandler::class, CancelOrderBySellerHandler::class, ShipOrderHandler::class,
    OrderProcessManager::class, PaymentGateway::class, StartPaymentHandler::class, RefundPayment::class,
]);

test('dispatcher Orderingu jest scoped i ma dokładnie sześć słuchaczy sagi', function () {
    $dispatcher = app(DomainEventDispatcher::class);

    $listeners = (new ReflectionProperty($dispatcher, 'listeners'))->getValue($dispatcher);

    expect($dispatcher)->toBeInstanceOf(InProcessDomainEventDispatcher::class)
        ->and($dispatcher)->toBe(app(InProcessDomainEventDispatcher::class))
        ->and(array_keys($listeners))->toEqualCanonicalizing([
            OrderPlaced::class, OrderRejected::class, OrderExpired::class,
            OrderCancelled::class, OrderPaid::class, RefundRequested::class,
        ]);
});

test('DbOfferSnapshots czyta ofertę, sprzedawcę i nazwę produktu jednym zapytaniem', function () {
    $sellerUser = User::factory()->create();
    $seller = SellerRow::factory()->forUser($sellerUser)->create();
    $listed = OfferRow::factory()->create(['seller_id' => $seller->id, 'product_id' => Product::factory()->create(['name' => 'Rower górski']), 'price_cents' => 12345, 'stock' => 3]);
    $soldOut = OfferRow::factory()->soldOut()->create(['seller_id' => $seller->id]);
    $draft = OfferRow::factory()->draft()->create(['seller_id' => $seller->id]);
    $ids = array_map(OfferId::fromInt(...), [$listed->id, $soldOut->id, $draft->id, 987654]);

    DB::enableQueryLog();
    $snapshots = app(OfferSnapshots::class)->forOffers($ids);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(1)
        ->and($snapshots)->toHaveCount(3)
        ->and($snapshots[$listed->id]->listed)->toBeTrue()
        ->and($snapshots[$listed->id]->sellerId->value())->toBe($seller->id)
        ->and($snapshots[$listed->id]->sellerUserId)->toBe($sellerUser->id)
        ->and($snapshots[$listed->id]->productName->value())->toBe('Rower górski')
        ->and($snapshots[$listed->id]->price->cents())->toBe(12345)
        ->and($snapshots[$soldOut->id]->listed)->toBeFalse()
        ->and($snapshots[$draft->id]->listed)->toBeFalse()
        ->and(app(OfferSnapshots::class)->forOffers([]))->toBe([]);
});

test('DbSellerAccounts mapuje użytkownika na sprzedawcę albo null', function () {
    $user = User::factory()->create();
    $seller = SellerRow::factory()->forUser($user)->create();

    expect(app(SellerAccounts::class)->sellerIdForUser($user->id)?->value())->toBe($seller->id)
        ->and(app(SellerAccounts::class)->sellerIdForUser(User::factory()->create()->id))->toBeNull();
});

test('PaymentGateway to adapter symulowanej bramki i rozwiązuje się razem z RefundPaymentJob', function () {
    $gateway = app(PaymentGateway::class);

    expect($gateway)->toBeInstanceOf(FakeGatewayPaymentGateway::class)
        ->and(app()->make(RefundPayment::class))->toBeObject()
        ->and((new ReflectionMethod(RefundPaymentJob::class, 'handle'))->getParameters()[0]->getType()->getName())->toBe(RefundPayment::class);
});

test('adapter bramki: startPayment zwraca adres sesji, a awaria zwrotu to PaymentGatewayUnavailable', function () {
    config(['services.fake_gateway.fail_first_refund' => true]);
    $gateway = app(PaymentGateway::class);

    $url = $gateway->startPayment(OrderId::fromInt(7), Money::fromCents(4498), new DateTimeImmutable('+15 minutes'), 'http://localhost/');
    $paymentId = basename($url);
    $refundId = RefundId::fromString('refund-7-1');

    expect(DB::table('fake_gateway_payments')->where('id', $paymentId)->value('reference'))->toBe('order-7')
        ->and(fn () => $gateway->refund($refundId, PaymentId::fromString($paymentId), Money::fromCents(4498)))->toThrow(PaymentGatewayUnavailable::class);

    $gateway->refund($refundId, PaymentId::fromString($paymentId), Money::fromCents(4498));
    expect(DB::table('fake_gateway_refunds')->where('idempotency_key', 'refund-7-1')->value('status'))->toBe('succeeded');
});
