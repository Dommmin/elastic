<?php

use App\Models\Product;
use App\Models\Seller as SellerRow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SellerPortal\Domain\Offer\Condition;
use SellerPortal\Domain\Offer\Offer;
use SellerPortal\Domain\Offer\ProductId;
use SellerPortal\Domain\Offer\Quantity;
use SellerPortal\Domain\Offer\ReservationKey;
use SellerPortal\Domain\Offer\ShippingTime;
use SellerPortal\Domain\Offer\Stock;
use SellerPortal\Domain\Offer\SuspensionReason;
use SellerPortal\Domain\Seller\City;
use SellerPortal\Domain\Seller\Seller;
use SellerPortal\Domain\Seller\SellerId;
use SellerPortal\Domain\Seller\SellerName;
use SellerPortal\Domain\Seller\SellerStatus;
use SellerPortal\Domain\Shared\ModeratorId;
use SellerPortal\Domain\Shared\Reason;
use SellerPortal\Infrastructure\Persistence\SqlOfferRepository;
use SellerPortal\Infrastructure\SystemClock;
use SharedKernel\Money;

beforeEach(function () {
    Carbon::setTestNow('2026-10-15 12:00:00');
});

function outboxEventsAt(string $modifier = '+0 seconds'): DateTimeImmutable
{
    return (new DateTimeImmutable('2026-10-15 12:00:00'))->modify($modifier);
}

function outboxEventsRepo(): SqlOfferRepository
{
    return new SqlOfferRepository(new SystemClock);
}

function outboxEventsActiveSeller(int $sellerId): Seller
{
    return Seller::reconstitute(SellerId::fromInt($sellerId), null, SellerName::fromString('Sklep testowy'), City::fromString('Poznań'), SellerStatus::Active, null, 1);
}

/**
 * Opublikowana oferta zapisana w bazie (wersja 2), przeładowana z repozytorium.
 */
function outboxEventsPublished(int $price = 10000, int $stock = 5): Offer
{
    $repo = outboxEventsRepo();
    $sellerId = SellerRow::factory()->create()->id;
    $offer = outboxEventsActiveSeller($sellerId)->draftOffer(
        $repo->nextIdentity(),
        ProductId::fromInt(Product::factory()->create()->id),
        Money::fromCents($price),
        Stock::fromInt($stock),
        Condition::New,
        ShippingTime::fromInt(3),
        outboxEventsAt(),
    );
    $offer->publish(outboxEventsActiveSeller($sellerId), outboxEventsAt());
    $repo->save($offer);

    return $repo->get($offer->id());
}

test('zapis nowej oferty wstawia offer.drafted i offer.published z sequence 1', function () {
    $offer = outboxEventsPublished();

    $rows = DB::table('outbox')->where('aggregate_id', (string) $offer->id()->value())->orderBy('id')->get();

    expect($rows->pluck('event_type')->all())->toBe(['offer.drafted', 'offer.published'])
        ->and($rows->pluck('sequence')->map(fn ($s) => (int) $s)->all())->toBe([1, 1])
        ->and($rows->pluck('aggregate_type')->unique()->all())->toBe(['offer'])
        ->and($rows->every(fn ($r) => strlen($r->event_id) === 26))->toBeTrue();
});

test('payload offer.price_changed ma product_id, old/new_price_cents i listed', function () {
    $repo = outboxEventsRepo();
    $offer = outboxEventsPublished(price: 10000);
    $offer->changePrice(Money::fromCents(11000), outboxEventsAt('+1 minute'));
    $repo->save($offer);

    $row = DB::table('outbox')->where('event_type', 'offer.price_changed')->first();
    $payload = json_decode($row->payload, true);

    expect($row->aggregate_id)->toBe((string) $offer->id()->value())
        ->and((int) $row->sequence)->toBe(2)
        ->and($payload)->toMatchArray([
            'id' => $offer->id()->value(),
            'product_id' => $offer->productId()->value(),
            'seller_id' => $offer->sellerId()->value(),
            'price_cents' => 11000,
            'currency' => 'PLN',
            'stock' => 5,
            'condition' => 'new',
            'shipping_days' => 3,
            'status' => 'published',
            'listed' => true,
            'version' => 2,
            'old_price_cents' => 10000,
            'new_price_cents' => 11000,
        ])
        ->and($payload)->not->toHaveKey('active');
});

test('zmiana ceny i stanu w jednym zapisie: dwa wiersze outboxu z tym samym sequence', function () {
    $repo = outboxEventsRepo();
    $offer = outboxEventsPublished();
    $offer->changePrice(Money::fromCents(10500), outboxEventsAt('+1 minute'));
    $offer->changeStock(Stock::fromInt(9), outboxEventsAt('+1 minute'));
    $repo->save($offer);

    $rows = DB::table('outbox')->where('sequence', 2)->orderBy('id')->get();

    expect($rows->pluck('event_type')->all())->toBe(['offer.price_changed', 'offer.stock_changed'])
        ->and(json_decode($rows[1]->payload, true))->toMatchArray(['old_stock' => 5, 'new_stock' => 9, 'version' => 2]);
});

test('wyprzedanie wstawia offer.sold_out z listed=false', function () {
    $repo = outboxEventsRepo();
    $offer = outboxEventsPublished();
    $offer->changeStock(Stock::fromInt(0), outboxEventsAt('+1 minute'));
    $repo->save($offer);

    $payload = json_decode(DB::table('outbox')->where('event_type', 'offer.sold_out')->value('payload'), true);

    expect($payload)->toMatchArray(['stock' => 0, 'listed' => false, 'status' => 'published']);
});

test('zawieszenie i zgłoszenie ceny niosą pola specyficzne dla zdarzenia', function () {
    $repo = outboxEventsRepo();
    $offer = outboxEventsPublished(price: 10000);
    $offer->changePrice(Money::fromCents(20000), outboxEventsAt('+1 minute'));
    $offer->suspend(SuspensionReason::byModerator(ModeratorId::fromInt(4), Reason::fromString('Spam')), outboxEventsAt('+2 minutes'));
    $repo->save($offer);

    $requested = json_decode(DB::table('outbox')->where('event_type', 'offer.price_change_requested')->value('payload'), true);
    $suspended = json_decode(DB::table('outbox')->where('event_type', 'offer.suspended')->value('payload'), true);

    expect($requested)->toMatchArray(['proposed_price_cents' => 20000, 'price_cents' => 10000])
        ->and($suspended)->toMatchArray(['suspension_kind' => 'moderation', 'suspension_note' => 'Spam', 'status' => 'suspended', 'listed' => false]);
});

test('decyzje moderatora i anulowanie mapują się na właściwe zdarzenia', function () {
    $repo = outboxEventsRepo();
    $offer = outboxEventsPublished(price: 10000);
    $offer->changePrice(Money::fromCents(20000), outboxEventsAt('+1 minute'));
    $offer->approvePriceChange(ModeratorId::fromInt(4), Money::fromCents(20000), outboxEventsAt('+2 minutes'));
    $offer->changePrice(Money::fromCents(40000), outboxEventsAt('+3 minutes'));
    $offer->rejectPriceChange(ModeratorId::fromInt(4), Money::fromCents(40000), Reason::fromString('Za drogo'), outboxEventsAt('+4 minutes'));
    $offer->changePrice(Money::fromCents(40000), outboxEventsAt('+5 minutes'));
    $offer->cancelPendingPriceChange(outboxEventsAt('+6 minutes'));
    $repo->save($offer);

    $payloads = DB::table('outbox')->whereIn('event_type', ['offer.price_change_approved', 'offer.price_change_rejected', 'offer.price_change_cancelled'])
        ->orderBy('id')->get()->mapWithKeys(fn ($r) => [$r->event_type => json_decode($r->payload, true)]);

    expect($payloads['offer.price_change_approved'])->toMatchArray(['old_price_cents' => 10000, 'new_price_cents' => 20000])
        ->and($payloads['offer.price_change_rejected'])->toMatchArray(['proposed_price_cents' => 40000, 'reason' => 'Za drogo'])
        ->and($payloads['offer.price_change_cancelled'])->toMatchArray(['proposed_price_cents' => 40000]);
});

test('occurred_at pochodzi ze zdarzenia domenowego', function () {
    $offer = outboxEventsPublished();

    $at = DB::table('outbox')->where('aggregate_id', (string) $offer->id()->value())->where('event_type', 'offer.drafted')->value('occurred_at');

    expect($at)->toStartWith('2026-10-15 12:00:00');
});

test('oferta bez zdarzeń (nic nie zmieniono) nie dodaje wierszy outboxu', function () {
    $repo = outboxEventsRepo();
    $offer = outboxEventsPublished();
    $before = DB::table('outbox')->count();

    $repo->save($repo->get($offer->id()));

    expect(DB::table('outbox')->count())->toBe($before);
});

function outboxEventsReserve(Offer $offer, string $key, int $quantity): void
{
    $offer->reserve(outboxEventsActiveSeller($offer->sellerId()->value()), ReservationKey::fromString($key), Quantity::fromInt($quantity), $offer->price(), outboxEventsAt('+1 minute'));
}

test('dwie rezerwacje w jednym zapisie: dwa wiersze outboxu offer.stock_reserved', function () {
    $repo = outboxEventsRepo();
    $offer = outboxEventsPublished(stock: 5);
    outboxEventsReserve($offer, 'order-a', 1);
    outboxEventsReserve($offer, 'order-b', 2);
    $repo->save($offer);

    $rows = DB::table('outbox')->where('event_type', 'offer.stock_reserved')->orderBy('id')->get();

    // `available` to stan oferty po zapisie (jak `listed`): oba wiersze dzielą ten sam sequence.
    expect($rows)->toHaveCount(2)
        ->and(json_decode($rows[0]->payload, true))->toMatchArray(['quantity' => 1, 'available' => 2])
        ->and(json_decode($rows[1]->payload, true))->toMatchArray(['quantity' => 2, 'available' => 2]);
});

test('payload offer.stock_reserved ma quantity, available, listed i nie ma klucza', function () {
    $repo = outboxEventsRepo();
    $offer = outboxEventsPublished(stock: 5);
    outboxEventsReserve($offer, 'order-a', 5);
    $repo->save($offer);

    $payload = json_decode(DB::table('outbox')->where('event_type', 'offer.stock_reserved')->value('payload'), true);

    expect($payload)->toMatchArray(['quantity' => 5, 'available' => 0, 'listed' => false, 'stock' => 5, 'status' => 'published'])
        ->and(array_key_exists('reservation_key', $payload))->toBeFalse()
        ->and(json_encode($payload))->not->toContain('order-a');
});

test('zwolnienie i potwierdzenie rezerwacji trafiają do outboxu z ilością i dostępnością', function () {
    $repo = outboxEventsRepo();
    $offer = outboxEventsPublished(stock: 5);
    outboxEventsReserve($offer, 'order-a', 2);
    outboxEventsReserve($offer, 'order-b', 1);
    $repo->save($offer);

    $offer = $repo->get($offer->id());
    $offer->releaseReservation(ReservationKey::fromString('order-a'), outboxEventsAt('+2 minutes'));
    $offer->confirmReservation(ReservationKey::fromString('order-b'), outboxEventsAt('+2 minutes'));
    $repo->save($offer);

    $released = json_decode(DB::table('outbox')->where('event_type', 'offer.reservation_released')->value('payload'), true);
    $confirmed = json_decode(DB::table('outbox')->where('event_type', 'offer.reservation_confirmed')->value('payload'), true);

    expect($released)->toMatchArray(['quantity' => 2, 'available' => 4, 'listed' => true])
        ->and($confirmed)->toMatchArray(['quantity' => 1, 'stock' => 4, 'available' => 4])
        ->and(array_key_exists('reservation_key', $released))->toBeFalse()
        ->and(array_key_exists('reservation_key', $confirmed))->toBeFalse();
});

test('payload bazowy każdego zdarzenia oferty ma available', function () {
    $offer = outboxEventsPublished(stock: 5);

    $payload = json_decode(DB::table('outbox')->where('aggregate_id', (string) $offer->id()->value())->where('event_type', 'offer.published')->value('payload'), true);

    expect($payload)->toMatchArray(['available' => 5]);
});
