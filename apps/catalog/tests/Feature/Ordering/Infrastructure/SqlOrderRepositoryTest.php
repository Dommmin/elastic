<?php

use App\Models\Offer as OfferRow;
use App\Models\Seller as SellerRow;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Ordering\Domain\BuyerId;
use Ordering\Domain\CancelledBy;
use Ordering\Domain\LineQuantity;
use Ordering\Domain\LineStatus;
use Ordering\Domain\OfferId;
use Ordering\Domain\Order;
use Ordering\Domain\OrderId;
use Ordering\Domain\OrderLine;
use Ordering\Domain\OrderNotFound;
use Ordering\Domain\OrderStatus;
use Ordering\Domain\PaymentId;
use Ordering\Domain\ProductName;
use Ordering\Domain\Reason;
use Ordering\Domain\RefundReason;
use Ordering\Domain\RefundStatus;
use Ordering\Domain\RejectionReason;
use Ordering\Domain\SellerId;
use Ordering\Domain\TrackingNumber;
use Ordering\Infrastructure\Persistence\SqlOrderRepository;
use SharedKernel\ConcurrencyConflict;
use SharedKernel\Money;

const ORDER_REPO_NOW = '2026-10-16 12:00:00';

function orderRepoNow(string $modifier = ''): DateTimeImmutable
{
    return (new DateTimeImmutable(ORDER_REPO_NOW))->modify($modifier ?: '+0 seconds');
}

/**
 * Zamówienie z dwiema pozycjami (2 × 1999 + 1 × 500), z prawdziwymi wierszami pod klucze obce.
 * Klucze obce w SQLite są włączone, więc brak oferty czy użytkownika wywala test.
 */
function orderRepoPlaced(?DateTimeImmutable $now = null): Order
{
    $repo = new SqlOrderRepository;
    $seller = SellerRow::factory()->create();
    $offers = OfferRow::factory()->count(2)->create(['seller_id' => $seller->id]);

    $order = Order::place(
        $repo->nextIdentity(),
        BuyerId::fromInt(User::factory()->create()->id),
        SellerId::fromInt($seller->id),
        [
            OrderLine::create(OfferId::fromInt($offers[0]->id), ProductName::fromString('Pierwszy'), Money::fromCents(1999), LineQuantity::fromInt(2)),
            OrderLine::create(OfferId::fromInt($offers[1]->id), ProductName::fromString('Drugi'), Money::fromCents(500), LineQuantity::fromInt(1)),
        ],
        $now ?? orderRepoNow(),
    );
    $repo->save($order);

    return $repo->get($order->id());
}

function orderRepoAwaitingPayment(?DateTimeImmutable $now = null): Order
{
    $repo = new SqlOrderRepository;
    $order = orderRepoPlaced($now);

    foreach ($order->lines() as $line) {
        $order->markLineReserved($line->offerId(), $now ?? orderRepoNow());
    }
    $repo->save($order);

    return $repo->get($order->id());
}

test('zapis i odczyt zamówienia zachowuje wszystkie pola', function () {
    $repo = new SqlOrderRepository;
    $order = orderRepoAwaitingPayment();
    $first = $order->lines()[0]->offerId();

    expect($order->version())->toBe(2)
        ->and($order->status())->toBe(OrderStatus::AwaitingPayment)
        ->and($order->lines())->toHaveCount(2)
        ->and($order->lines()[0]->offerId()->equals($first))->toBeTrue()
        ->and($order->lines()[0]->productName()->value())->toBe('Pierwszy')
        ->and($order->lines()[0]->unitPrice()->cents())->toBe(1999)
        ->and($order->lines()[0]->quantity()->value())->toBe(2)
        ->and($order->lines()[0]->status())->toBe(LineStatus::Reserved)
        ->and($order->lines()[1]->productName()->value())->toBe('Drugi')
        ->and($order->total()->cents())->toBe(4498)
        ->and($order->placedAt()->format('c'))->toBe(orderRepoNow()->format('c'))
        ->and($order->paymentDeadline()->format('c'))->toBe(orderRepoNow('+15 minutes')->format('c'))
        ->and($order->payment())->toBeNull()
        ->and($order->refunds())->toBe([]);

    // Wpłata, anulowanie przez sprzedawcę i zwrot.
    $order->recordPayment(PaymentId::fromString('pay-1'), $order->total(), orderRepoNow('+5 minutes'));
    $order->cancelBySeller(Reason::fromString('Brak towaru'), orderRepoNow('+1 hour'));
    $repo->save($order);

    $loaded = $repo->get($order->id());
    expect($loaded->version())->toBe(3)
        ->and($loaded->status())->toBe(OrderStatus::Cancelled)
        ->and($loaded->payment()->paymentId->value())->toBe('pay-1')
        ->and($loaded->payment()->amount->cents())->toBe(4498)
        ->and($loaded->payment()->paidAt->format('c'))->toBe(orderRepoNow('+5 minutes')->format('c'))
        ->and($loaded->cancellation()->by)->toBe(CancelledBy::Seller)
        ->and($loaded->cancellation()->reason->value())->toBe('Brak towaru')
        ->and($loaded->refunds())->toHaveCount(1)
        ->and($loaded->refunds()[0]->reason)->toBe(RefundReason::CancelledBySeller)
        ->and($loaded->refunds()[0]->status)->toBe(RefundStatus::Requested)
        ->and($loaded->refunds()[0]->completedAt)->toBeNull();

    // Zakończenie zwrotu aktualizuje istniejący wiersz, nie dubluje go.
    $loaded->markRefundCompleted($loaded->refunds()[0]->refundId, orderRepoNow('+2 hours'));
    $repo->save($loaded);

    $done = $repo->get($order->id());
    expect($done->refunds())->toHaveCount(1)
        ->and($done->refunds()[0]->status)->toBe(RefundStatus::Completed)
        ->and($done->refunds()[0]->completedAt->format('c'))->toBe(orderRepoNow('+2 hours')->format('c'))
        ->and(DB::table('order_refunds')->count())->toBe(1);
});

test('zapis i odczyt zachowuje wysyłkę i odrzucenie', function () {
    $repo = new SqlOrderRepository;

    $shipped = orderRepoAwaitingPayment();
    $shipped->recordPayment(PaymentId::fromString('pay-2'), $shipped->total(), orderRepoNow('+1 minute'));
    $shipped->ship(TrackingNumber::fromString('PL123456'), orderRepoNow('+1 day'));
    $repo->save($shipped);

    $loaded = $repo->get($shipped->id());
    expect($loaded->status())->toBe(OrderStatus::Shipped)
        ->and($loaded->shipment()->trackingNumber->value())->toBe('PL123456')
        ->and($loaded->shipment()->shippedAt->format('c'))->toBe(orderRepoNow('+1 day')->format('c'));

    $rejected = orderRepoPlaced();
    $offerId = $rejected->lines()[1]->offerId();
    $rejected->reject(RejectionReason::OutOfStock, $offerId, orderRepoNow('+1 second'));
    $repo->save($rejected);

    $loaded = $repo->get($rejected->id());
    expect($loaded->status())->toBe(OrderStatus::Rejected)
        ->and($loaded->rejection()->reason)->toBe(RejectionReason::OutOfStock)
        ->and($loaded->rejection()->offerId->equals($offerId))->toBeTrue();
});

test('pozycje wracają w kolejności position', function () {
    $repo = new SqlOrderRepository;
    $order = orderRepoPlaced();
    $ids = array_map(fn (OrderLine $l) => $l->offerId()->value(), $order->lines());

    // Odwrócona kolejność wstawienia fizycznych wierszy nie może zmienić wyniku.
    DB::table('order_lines')->where('order_id', $order->id()->value())->where('position', 0)->update(['position' => 5]);
    DB::table('order_lines')->where('order_id', $order->id()->value())->where('position', 1)->update(['position' => 0]);
    DB::table('order_lines')->where('order_id', $order->id()->value())->where('position', 5)->update(['position' => 1]);

    $loaded = $repo->get($order->id());

    expect(array_map(fn (OrderLine $l) => $l->offerId()->value(), $loaded->lines()))->toBe(array_reverse($ids));
});

test('drugi zapis tej samej wersji rzuca ConcurrencyConflict', function () {
    $repo = new SqlOrderRepository;
    $id = orderRepoPlaced()->id();

    $first = $repo->get($id);
    $second = $repo->get($id);

    $first->cancelByBuyer(orderRepoNow('+1 minute'));
    $repo->save($first);

    $second->cancelByBuyer(orderRepoNow('+2 minutes'));

    expect(fn () => $repo->save($second))->toThrow(ConcurrencyConflict::class)
        ->and($repo->get($id)->version())->toBe(2);
});

test('każde zdarzenie zapisuje wiersz order_events', function () {
    $repo = new SqlOrderRepository;
    $order = orderRepoPlaced();
    $id = $order->id()->value();

    $order->markLineReserved($order->lines()[0]->offerId(), orderRepoNow('+1 second'));
    $order->markLineReserved($order->lines()[1]->offerId(), orderRepoNow('+2 seconds'));
    $repo->save($order);

    $rows = DB::table('order_events')->where('order_id', $id)->orderBy('id')->get();

    expect($rows->pluck('event_type')->all())->toBe([
        'order.placed', 'order.line_reserved', 'order.line_reserved', 'order.awaiting_payment',
    ]);

    $placed = json_decode($rows[0]->payload, true);
    expect($placed)->toBe([
        'order_id' => $id,
        'buyer_id' => $order->buyerId()->value(),
        'seller_id' => $order->sellerId()->value(),
        'total_cents' => 4498,
        'total_currency' => 'PLN',
    ]);

    $reserved = json_decode($rows[1]->payload, true);
    expect($reserved)->toBe(['order_id' => $id, 'offer_id' => $order->lines()[0]->offerId()->value()]);

    $awaiting = json_decode($rows[3]->payload, true);
    expect($awaiting['deadline'])->toBe(orderRepoNow('+15 minutes')->format(DATE_ATOM));

    // Zdarzenia zwrotu i spóźnionej wpłaty.
    $loaded = $repo->get($order->id());
    $loaded->recordPayment(PaymentId::fromString('pay-1'), $loaded->total(), orderRepoNow('+3 minutes'));
    $loaded->recordPayment(PaymentId::fromString('pay-2'), $loaded->total(), orderRepoNow('+4 minutes'));
    $repo->save($loaded);

    $types = DB::table('order_events')->where('order_id', $id)->orderBy('id')->pluck('event_type')->all();
    expect(array_slice($types, 4))->toBe(['order.paid', 'order.late_payment_received', 'order.refund_requested']);

    $refund = json_decode(DB::table('order_events')->where('event_type', 'order.refund_requested')->value('payload'), true);
    expect($refund['reason'])->toBe('duplicate_payment')
        ->and($refund['amount_cents'])->toBe(4498)
        ->and($refund['payment_id'])->toBe('pay-2');
});

test('nieudany zapis nie zostawia wierszy zdarzeń', function () {
    $repo = new SqlOrderRepository;
    $id = orderRepoPlaced()->id();
    $stale = $repo->get($id);
    $fresh = $repo->get($id);

    $fresh->cancelByBuyer(orderRepoNow('+1 minute'));
    $repo->save($fresh);
    $before = DB::table('order_events')->count();

    $stale->cancelByBuyer(orderRepoNow('+2 minutes'));

    expect(fn () => $repo->save($stale))->toThrow(ConcurrencyConflict::class)
        ->and(DB::table('order_events')->count())->toBe($before);
});

test('idsDueForExpiry uwzględnia karencję i pomija opłacone', function () {
    $repo = new SqlOrderRepository;

    // Termin = +15 min, karencja +5 min => wymagalne od +20 min.
    $placed = orderRepoPlaced();
    $awaiting = orderRepoAwaitingPayment();
    $paid = orderRepoAwaitingPayment();
    $paid->recordPayment(PaymentId::fromString('pay-1'), $paid->total(), orderRepoNow('+1 minute'));
    $repo->save($paid);

    expect($repo->idsDueForExpiry(orderRepoNow('+19 minutes 59 seconds'), 0, 10))->toBe([])
        ->and(array_map(fn (OrderId $i) => $i->value(), $repo->idsDueForExpiry(orderRepoNow('+20 minutes'), 0, 10)))
        ->toBe([$placed->id()->value(), $awaiting->id()->value()]);
});

test('idsDueForExpiry stronicuje po id', function () {
    $repo = new SqlOrderRepository;
    $a = orderRepoPlaced();
    $b = orderRepoPlaced();
    $c = orderRepoPlaced();
    $now = orderRepoNow('+1 hour');

    $page = $repo->idsDueForExpiry($now, 0, 2);
    expect(array_map(fn (OrderId $i) => $i->value(), $page))->toBe([$a->id()->value(), $b->id()->value()]);

    $next = $repo->idsDueForExpiry($now, $b->id()->value(), 2);
    expect(array_map(fn (OrderId $i) => $i->value(), $next))->toBe([$c->id()->value()]);
});

test('wygasłe zamówienie nie jest już wymagalne', function () {
    $repo = new SqlOrderRepository;
    $order = orderRepoPlaced();
    $order->expire(orderRepoNow('+1 hour'));
    $repo->save($order);

    expect($repo->idsDueForExpiry(orderRepoNow('+2 hours'), 0, 10))->toBe([]);
});

test('odczyt nieistniejącego rzuca OrderNotFound', function () {
    expect(fn () => (new SqlOrderRepository)->get(OrderId::fromInt(999)))->toThrow(OrderNotFound::class);
});
