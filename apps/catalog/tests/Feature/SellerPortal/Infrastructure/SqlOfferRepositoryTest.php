<?php

use App\Models\Product;
use App\Models\Seller as SellerRow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SellerPortal\Domain\Offer\Condition;
use SellerPortal\Domain\Offer\LiveOfferAlreadyExists;
use SellerPortal\Domain\Offer\Offer;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Offer\OfferNotFound;
use SellerPortal\Domain\Offer\OfferStatus;
use SellerPortal\Domain\Offer\ProductId;
use SellerPortal\Domain\Offer\Quantity;
use SellerPortal\Domain\Offer\ReservationKey;
use SellerPortal\Domain\Offer\ReservationStatus;
use SellerPortal\Domain\Offer\ShippingTime;
use SellerPortal\Domain\Offer\Stock;
use SellerPortal\Domain\Offer\SuspensionKind;
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
use SharedKernel\ConcurrencyConflict;
use SharedKernel\Money;

const OFFER_REPO_NOW = '2026-10-15 12:00:00';

beforeEach(function () {
    Carbon::setTestNow(OFFER_REPO_NOW);
});

function offerRepoNow(string $modifier = ''): DateTimeImmutable
{
    return (new DateTimeImmutable(OFFER_REPO_NOW))->modify($modifier ?: '+0 seconds');
}

function offerRepo(): SqlOfferRepository
{
    return new SqlOfferRepository(new SystemClock);
}

/**
 * Szkic zapisany w bazie przez repozytorium (wersja 1 po zapisie).
 */
function offerRepoSavedDraft(?int $sellerId = null, ?int $productId = null, int $price = 10000, int $stock = 5): OfferId
{
    $repo = offerRepo();
    $sellerId ??= SellerRow::factory()->create()->id;
    $productId ??= Product::factory()->create()->id;

    $offer = Offer::draft(
        $repo->nextIdentity(),
        SellerId::fromInt($sellerId),
        ProductId::fromInt($productId),
        Money::fromCents($price),
        Stock::fromInt($stock),
        Condition::New,
        ShippingTime::fromInt(3),
        offerRepoNow(),
    );
    $repo->save($offer);

    return $offer->id();
}

test('zapis i odczyt oferty zachowuje wszystkie pola', function () {
    $repo = offerRepo();
    $id = offerRepoSavedDraft(price: 10000, stock: 5);

    $offer = $repo->get($id);
    $offer->publish(
        Seller::reconstitute(
            $offer->sellerId(),
            null,
            SellerName::fromString('Sklep testowy'),
            City::fromString('Poznań'),
            SellerStatus::Active,
            null,
            1,
        ),
        offerRepoNow(),
    );
    // +50% => ponad próg 30%: zgłoszenie do akceptacji (trafia też do okna limitu).
    $offer->changePrice(Money::fromCents(15000), offerRepoNow('+1 minute'));
    $offer->suspend(SuspensionReason::byModerator(ModeratorId::fromInt(7), Reason::fromString('Podejrzana oferta')), offerRepoNow('+2 minutes'));
    $repo->save($offer);

    $loaded = $repo->get($id);

    expect($loaded->version())->toBe(2)
        ->and($loaded->status())->toBe(OfferStatus::Suspended)
        ->and($loaded->price()->cents())->toBe(10000)
        ->and($loaded->stock()->value())->toBe(5)
        ->and($loaded->condition())->toBe(Condition::New)
        ->and($loaded->shippingTime()->value())->toBe(3)
        ->and($loaded->suspension()->kind())->toBe(SuspensionKind::Moderation)
        ->and($loaded->suspension()->moderatorId()->value())->toBe(7)
        ->and($loaded->suspension()->note()->value())->toBe('Podejrzana oferta')
        ->and($loaded->pendingPriceChange()->price()->cents())->toBe(15000)
        ->and($loaded->pendingPriceChange()->requestedAt()->format('c'))->toBe(offerRepoNow('+1 minute')->format('c'))
        ->and($loaded->recentPriceChanges())->toHaveCount(1)
        ->and($loaded->recentPriceChanges()[0]->format('c'))->toBe(offerRepoNow('+1 minute')->format('c'));
});

test('nowy agregat ma wersję 0, a po zapisie baza ma wersję 1', function () {
    $id = offerRepoSavedDraft();

    expect(DB::table('offers')->where('id', $id->value())->value('version'))->toBe(1)
        ->and(offerRepo()->get($id)->version())->toBe(1);
});

test('nieistniejąca oferta rzuca OfferNotFound', function () {
    offerRepo()->get(OfferId::fromInt(999));
})->throws(OfferNotFound::class);

test('wpisy zmian ceny starsze niż 24 h nie są ładowane', function () {
    $id = offerRepoSavedDraft();
    DB::table('offer_price_changes')->insert([
        ['offer_id' => $id->value(), 'changed_at' => '2026-10-14 11:00:00.000000'],
        ['offer_id' => $id->value(), 'changed_at' => '2026-10-14 13:00:00.000000'],
    ]);

    $changes = offerRepo()->get($id)->recentPriceChanges();

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->format('Y-m-d H:i'))->toBe('2026-10-14 13:00');
});

test('kolejny zapis nie dubluje wierszy historii cen', function () {
    $repo = offerRepo();
    $id = offerRepoSavedDraft();
    $offer = $repo->get($id);
    $offer->publish(Seller::reconstitute(
        $offer->sellerId(), null,
        SellerName::fromString('Sklep testowy'),
        City::fromString('Poznań'),
        SellerStatus::Active, null, 1,
    ), offerRepoNow());
    $offer->changePrice(Money::fromCents(10500), offerRepoNow('+1 minute'));
    $repo->save($offer);

    $again = $repo->get($id);
    $again->changeStock(Stock::fromInt(9), offerRepoNow('+2 minutes'));
    $repo->save($again);

    expect(DB::table('offer_price_changes')->where('offer_id', $id->value())->count())->toBe(1);
});

test('drugi zapis tej samej wersji rzuca ConcurrencyConflict', function () {
    $repo = offerRepo();
    $id = offerRepoSavedDraft();

    $first = $repo->get($id);
    $second = $repo->get($id);
    $first->changeStock(Stock::fromInt(10), offerRepoNow('+1 minute'));
    $second->changeStock(Stock::fromInt(20), offerRepoNow('+1 minute'));

    $repo->save($first);

    expect(fn () => $repo->save($second))->toThrow(ConcurrencyConflict::class)
        ->and(DB::table('offers')->where('id', $id->value())->value('stock'))->toBe(10)
        ->and(DB::table('outbox')->where('event_type', 'offer.stock_changed')->count())->toBe(1);
});

test('druga żywa oferta → LiveOfferAlreadyExists, nie QueryException', function () {
    $sellerId = SellerRow::factory()->create()->id;
    $productId = Product::factory()->create()->id;
    offerRepoSavedDraft($sellerId, $productId);

    expect(fn () => offerRepoSavedDraft($sellerId, $productId))->toThrow(LiveOfferAlreadyExists::class);
});

test('po nieudanym zapisie drugiej żywej oferty nie zostaje wiersz outboxu', function () {
    $sellerId = SellerRow::factory()->create()->id;
    $productId = Product::factory()->create()->id;
    offerRepoSavedDraft($sellerId, $productId);
    $before = DB::table('outbox')->count();

    try {
        offerRepoSavedDraft($sellerId, $productId);
    } catch (LiveOfferAlreadyExists) {
    }

    expect(DB::table('outbox')->count())->toBe($before);
});

test('hasLiveOffer ignoruje wycofane', function () {
    $repo = offerRepo();
    $sellerId = SellerRow::factory()->create()->id;
    $productId = Product::factory()->create()->id;
    $id = offerRepoSavedDraft($sellerId, $productId);

    expect($repo->hasLiveOffer(SellerId::fromInt($sellerId), ProductId::fromInt($productId)))->toBeTrue();

    $offer = $repo->get($id);
    $offer->withdraw(offerRepoNow('+1 minute'));
    $repo->save($offer);

    expect($repo->hasLiveOffer(SellerId::fromInt($sellerId), ProductId::fromInt($productId)))->toBeFalse();

    // Po wycofaniu wolno wystawić nową ofertę na ten sam produkt.
    offerRepoSavedDraft($sellerId, $productId);
    expect($repo->hasLiveOffer(SellerId::fromInt($sellerId), ProductId::fromInt($productId)))->toBeTrue();
});

test('idsForSellerPolicy stronicuje po id i filtruje rodzaj zawieszenia', function () {
    $repo = offerRepo();
    $seller = SellerRow::factory()->create();
    $other = SellerRow::factory()->create();

    $blockedIds = [];
    foreach (range(1, 3) as $i) {
        $blockedIds[] = DB::table('offers')->insertGetId(offerRepoRawRow($seller->id, ['status' => 'suspended', 'suspension_kind' => 'seller_blocked']));
    }
    $moderated = DB::table('offers')->insertGetId(offerRepoRawRow($seller->id, ['status' => 'suspended', 'suspension_kind' => 'moderation', 'suspension_note' => 'x', 'suspended_by' => 3]));
    DB::table('offers')->insert(offerRepoRawRow($other->id, ['status' => 'suspended', 'suspension_kind' => 'seller_blocked']));
    DB::table('offers')->insert(offerRepoRawRow($seller->id, ['status' => 'published']));

    $sellerId = SellerId::fromInt($seller->id);
    $ids = fn (array $list): array => array_map(fn (OfferId $id) => $id->value(), $list);

    $page1 = $repo->idsForSellerPolicy($sellerId, OfferStatus::Suspended, SuspensionKind::SellerBlocked, 0, 2);
    $page2 = $repo->idsForSellerPolicy($sellerId, OfferStatus::Suspended, SuspensionKind::SellerBlocked, $ids($page1)[1], 2);
    $all = $repo->idsForSellerPolicy($sellerId, OfferStatus::Suspended, null, 0, 10);
    $published = $repo->idsForSellerPolicy($sellerId, OfferStatus::Published, null, 0, 10);

    expect($ids($page1))->toBe([$blockedIds[0], $blockedIds[1]])
        ->and($ids($page2))->toBe([$blockedIds[2]])
        ->and($ids($all))->toBe([...$blockedIds, $moderated])
        ->and($published)->toHaveCount(1);
});

test('uszkodzony wiersz (zawieszona bez rodzaju) rzuca wyjątek z numerem oferty', function () {
    $seller = SellerRow::factory()->create();
    $id = DB::table('offers')->insertGetId(offerRepoRawRow($seller->id, ['status' => 'suspended', 'suspension_kind' => null]));

    expect(fn () => offerRepo()->get(OfferId::fromInt($id)))
        ->toThrow(UnexpectedValueException::class, "oferty #{$id}");
});

function offerRepoRawRow(int $sellerId, array $overrides = []): array
{
    return $overrides + [
        'product_id' => Product::factory()->create()->id,
        'seller_id' => $sellerId,
        'price_cents' => 10000,
        'currency' => 'PLN',
        'stock' => 5,
        'condition' => 'new',
        'shipping_days' => 3,
        'status' => 'draft',
        'version' => 1,
    ];
}

function offerRepoActiveSeller(Offer $offer): Seller
{
    return Seller::reconstitute($offer->sellerId(), null, SellerName::fromString('Sklep testowy'), City::fromString('Poznań'), SellerStatus::Active, null, 1);
}

/**
 * Opublikowana oferta zapisana w bazie, przeładowana z repozytorium.
 */
function offerRepoSavedPublished(int $stock = 10, int $price = 10000): Offer
{
    $repo = offerRepo();
    $offer = $repo->get(offerRepoSavedDraft(price: $price, stock: $stock));
    $offer->publish(offerRepoActiveSeller($offer), offerRepoNow());
    $repo->save($offer);

    return $repo->get($offer->id());
}

function offerRepoReserve(Offer $offer, string $key, int $quantity, int $price = 10000): void
{
    $offer->reserve(offerRepoActiveSeller($offer), ReservationKey::fromString($key), Quantity::fromInt($quantity), Money::fromCents($price), offerRepoNow());
}

test('rezerwacje wracają z bazy, tylko aktywne', function () {
    $repo = offerRepo();
    $offer = offerRepoSavedPublished();
    offerRepoReserve($offer, 'order-a', 2);
    offerRepoReserve($offer, 'order-b', 3);
    $repo->save($offer);

    $offer = $repo->get($offer->id());
    $offer->releaseReservation(ReservationKey::fromString('order-a'), offerRepoNow('+1 minute'));
    $repo->save($offer);

    $loaded = $repo->get($offer->id());

    expect($loaded->reservations())->toHaveCount(1)
        ->and($loaded->reservations()[0]->key->value())->toBe('order-b')
        ->and($loaded->reservations()[0]->quantity->value())->toBe(3)
        ->and($loaded->reservations()[0]->reservedAt->format('c'))->toBe(offerRepoNow()->format('c'))
        ->and($loaded->reserved())->toBe(3)
        ->and($loaded->available())->toBe(7);
});

test('reservationStatus rozróżnia active, confirmed, released i brak', function () {
    $repo = offerRepo();
    $offer = offerRepoSavedPublished();
    foreach (['order-a', 'order-b', 'order-c'] as $key) {
        offerRepoReserve($offer, $key, 1);
    }
    $repo->save($offer);

    $offer = $repo->get($offer->id());
    $offer->releaseReservation(ReservationKey::fromString('order-a'), offerRepoNow('+1 minute'));
    $offer->confirmReservation(ReservationKey::fromString('order-b'), offerRepoNow('+1 minute'));
    $repo->save($offer);

    $id = $offer->id();

    expect($repo->reservationStatus($id, ReservationKey::fromString('order-a')))->toBe(ReservationStatus::Released)
        ->and($repo->reservationStatus($id, ReservationKey::fromString('order-b')))->toBe(ReservationStatus::Confirmed)
        ->and($repo->reservationStatus($id, ReservationKey::fromString('order-c')))->toBe(ReservationStatus::Active)
        ->and($repo->reservationStatus($id, ReservationKey::fromString('order-z')))->toBeNull();

    $settled = DB::table('offer_reservations')->where('reservation_key', 'order-b')->first();
    expect($settled->settled_at)->not->toBeNull()
        ->and(DB::table('offer_reservations')->where('reservation_key', 'order-c')->value('settled_at'))->toBeNull()
        ->and($repo->get($id)->stock()->value())->toBe(9);
});

test('zapis oferty bez zdarzeń rezerwacji nie rusza zapisanych rezerwacji', function () {
    $repo = offerRepo();
    $offer = offerRepoSavedPublished();
    offerRepoReserve($offer, 'order-a', 4);
    $repo->save($offer);

    $reloaded = $repo->get($offer->id());
    $reloaded->changeStock(Stock::fromInt(20), offerRepoNow('+1 minute'));
    $repo->save($reloaded);

    expect(DB::table('offer_reservations')->where('offer_id', $offer->id()->value())->get())->toHaveCount(1)
        ->and(DB::table('offer_reservations')->where('reservation_key', 'order-a')->value('status'))->toBe('active')
        ->and($repo->get($offer->id())->reserved())->toBe(4);
});

test('konflikt wersji cofa też zapis rezerwacji', function () {
    $repo = offerRepo();
    $offer = offerRepoSavedPublished();
    $stale = $repo->get($offer->id());

    offerRepoReserve($offer, 'order-a', 1);
    $repo->save($offer);

    offerRepoReserve($stale, 'order-b', 1);

    expect(fn () => $repo->save($stale))->toThrow(ConcurrencyConflict::class)
        ->and(DB::table('offer_reservations')->pluck('reservation_key')->all())->toBe(['order-a']);
});
