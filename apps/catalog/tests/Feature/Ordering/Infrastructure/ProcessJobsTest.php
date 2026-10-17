<?php

use App\Models\Offer as OfferRow;
use App\Models\Seller as SellerRow;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Ordering\Application\Order\CartLine;
use Ordering\Application\Order\PlaceOrdersFromCart;
use Ordering\Application\Order\PlaceOrdersFromCartHandler;
use Ordering\Application\Order\RecordPayment;
use Ordering\Application\Order\RecordPaymentHandler;
use Ordering\Application\Port\BuyerCart;
use Ordering\Application\Port\DomainEventDispatcher;
use Ordering\Application\Port\ProcessJobQueue;
use Ordering\Application\Process\ConfirmOrderReservations;
use Ordering\Domain\BuyerId;
use Ordering\Domain\Event\OrderPlaced;
use Ordering\Domain\OrderId;
use Ordering\Domain\RefundId;
use Ordering\Infrastructure\InProcessDomainEventDispatcher;
use Ordering\Infrastructure\Job\ConfirmOrderReservationsJob;
use Ordering\Infrastructure\Job\RefundPaymentJob;
use Ordering\Infrastructure\Job\ReleaseOrderReservationsJob;
use Ordering\Infrastructure\Job\ReserveOrderLinesJob;

beforeEach(function () {
    // Koszyk to port z adapterem w późniejszym zadaniu: tu wystarczy atrapa.
    app()->instance(BuyerCart::class, new class implements BuyerCart
    {
        public function clear(BuyerId $buyerId, array $offerIds): void {}
    });
});

/**
 * @return array{0: int, 1: OfferRow} ID kupującego i oferta (cena 10000, stan 5)
 */
function processJobsScenario(): array
{
    $offer = OfferRow::factory()->create([
        'seller_id' => SellerRow::factory()->forUser(User::factory()->create())->create()->id,
        'price_cents' => 10000,
        'stock' => 5,
    ]);

    return [User::factory()->create()->id, $offer];
}

function processJobsPlace(int $buyer, OfferRow $offer, int $quantity = 2): OrderId
{
    return app(PlaceOrdersFromCartHandler::class)->handle(
        new PlaceOrdersFromCart($buyer, [new CartLine($offer->id, $quantity, 10000)])
    )[0];
}

test('złożenie zamówienia wstawia job w tej samej transakcji', function () {
    config(['queue.ordering_process_connection' => 'database']);
    [$buyer, $offer] = processJobsScenario();

    $orderId = processJobsPlace($buyer, $offer);

    $jobs = DB::table('jobs')->get();
    expect($jobs)->toHaveCount(1)
        ->and(json_decode($jobs[0]->payload, true)['displayName'])->toBe(ReserveOrderLinesJob::class)
        ->and(DB::table('orders')->where('id', $orderId->value())->count())->toBe(1);
});

test('wycofana transakcja nie zostawia joba', function () {
    config(['queue.ordering_process_connection' => 'database']);
    [$buyer, $offer] = processJobsScenario();
    app(DomainEventDispatcher::class)->listen(OrderPlaced::class, function () {
        throw new RuntimeException('awaria po kolejkowaniu');
    });

    expect(fn () => processJobsPlace($buyer, $offer))->toThrow(RuntimeException::class);

    expect(DB::table('orders')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('joby mają ponowienia z backoffem', function (object $job) {
    expect($job->tries)->toBe(5)->and($job->backoff)->toBe([5, 30, 120]);
})->with([
    fn () => new ReserveOrderLinesJob(1),
    fn () => new ReleaseOrderReservationsJob(1),
    fn () => new ConfirmOrderReservationsJob(1),
    fn () => new RefundPaymentJob(1, 'refund-1'),
]);

test('kolejka sagi trafia na połączenie z configu mimo queue.default', function (string $default) {
    config(['queue.default' => $default, 'queue.ordering_process_connection' => 'database']);
    // Maszyna współdzielona: Redis na domyślnym porcie bywa cudzy (inny projekt).
    // Port 1 nie przyjmie połączenia, więc job, który mimo wszystko poszedłby
    // na `queue.default`, wywróci test zamiast trafić do obcej kolejki.
    config([
        'database.redis.default.host' => '127.0.0.1',
        'database.redis.default.port' => 1,
        'database.redis.default.url' => null,
        'database.redis.cache.host' => '127.0.0.1',
        'database.redis.cache.port' => 1,
        'database.redis.cache.url' => null,
    ]);
    [$buyer, $offer] = processJobsScenario();

    processJobsPlace($buyer, $offer);

    expect(DB::table('jobs')->count())->toBe(1);
})->with(['redis', 'sync']);

test('saga na bazie: złożone → AwaitingPayment, oferta ma rezerwację', function () {
    config(['queue.ordering_process_connection' => 'sync']);
    [$buyer, $offer] = processJobsScenario();

    $orderId = processJobsPlace($buyer, $offer, 2);

    expect(DB::table('orders')->where('id', $orderId->value())->value('status'))->toBe('awaiting_payment')
        ->and(DB::table('offer_reservations')->where('offer_id', $offer->id)->where('reservation_key', 'order-'.$orderId->value())->where('status', 'active')->value('quantity'))->toBe(2)
        ->and((int) DB::table('offers')->where('id', $offer->id)->value('stock'))->toBe(5);
});

test('saga odrzuca zamówienie, gdy towaru brakuje, i oddaje rezerwacje', function () {
    config(['queue.ordering_process_connection' => 'sync']);
    [$buyer, $offer] = processJobsScenario();
    $offer->update(['stock' => 1]);

    $orderId = processJobsPlace($buyer, $offer, 2);

    expect(DB::table('orders')->where('id', $orderId->value())->value('status'))->toBe('rejected')
        ->and(DB::table('offer_reservations')->where('status', 'active')->count())->toBe(0);
});

test('confirm po płatności zmniejsza stan w bazie, ponowienie joba nic nie zmienia', function () {
    config(['queue.ordering_process_connection' => 'sync']);
    [$buyer, $offer] = processJobsScenario();
    $orderId = processJobsPlace($buyer, $offer, 2);

    app(RecordPaymentHandler::class)->handle(new RecordPayment($orderId->value(), 'pay-1', 20000, 'PLN'));
    (new ConfirmOrderReservationsJob($orderId->value()))->handle(app(ConfirmOrderReservations::class));

    expect(DB::table('orders')->where('id', $orderId->value())->value('status'))->toBe('paid')
        ->and((int) DB::table('offers')->where('id', $offer->id)->value('stock'))->toBe(3)
        ->and(DB::table('offer_reservations')->where('offer_id', $offer->id)->value('status'))->toBe('confirmed');
});

test('słuchacze sagi przeżywają flush instancji scoped (Octane)', function () {
    config(['queue.ordering_process_connection' => 'database']);
    [$buyer, $offer] = processJobsScenario();
    app(InProcessDomainEventDispatcher::class);
    app()->forgetScopedInstances();

    processJobsPlace($buyer, $offer);

    expect(DB::table('jobs')->count())->toBe(1);
});

test('refundId trafia do payloadu joba zwrotu', function () {
    config(['queue.ordering_process_connection' => 'database']);

    app(ProcessJobQueue::class)->refund(OrderId::fromInt(7), RefundId::fromString('refund-7'));

    $payload = json_decode(DB::table('jobs')->value('payload'), true);
    expect($payload['displayName'])->toBe(RefundPaymentJob::class);
});
