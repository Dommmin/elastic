<?php

use App\Models\Offer as OfferRow;
use App\Models\Seller as SellerRow;
use Illuminate\Support\Facades\DB;
use Ordering\Application\Port\ReservationResult;
use Ordering\Application\Port\StockReservations;
use Ordering\Domain\LineQuantity;
use Ordering\Domain\OfferId;
use SellerPortal\Domain\Offer\ReservationConflict;
use SharedKernel\Money;

function stockAdapterOffer(array $offer = [], array $seller = []): OfferRow
{
    return OfferRow::factory()->create([
        'seller_id' => SellerRow::factory()->create($seller)->id,
        'price_cents' => 10000,
        'stock' => 5,
        ...$offer,
    ]);
}

function stockAdapterReserve(OfferRow|int $offer, int $quantity = 2, int $cents = 10000, string $key = 'order-1'): ReservationResult
{
    $id = $offer instanceof OfferRow ? $offer->id : $offer;

    return app(StockReservations::class)->reserve($key, OfferId::fromInt($id), LineQuantity::fromInt($quantity), Money::fromCents($cents));
}

test('adapter tłumaczy wyjątki portalu na ReservationResult', function (callable $arrange, ReservationResult $expected) {
    expect(stockAdapterReserve(...$arrange()))->toBe($expected);
})->with([
    'udana rezerwacja' => [fn () => [stockAdapterOffer()], ReservationResult::Reserved],
    'za mało towaru' => [fn () => [stockAdapterOffer(), 6], ReservationResult::OutOfStock],
    'inna cena' => [fn () => [stockAdapterOffer(), 1, 9999], ReservationResult::PriceChanged],
    'oferta nieopublikowana' => [fn () => [stockAdapterOffer(['status' => 'draft'])], ReservationResult::OfferUnavailable],
    'sprzedawca zablokowany' => [fn () => [stockAdapterOffer([], ['status' => 'blocked'])], ReservationResult::OfferUnavailable],
    'brak oferty' => [fn () => [987654], ReservationResult::OfferUnavailable],
]);

test('rezerwacja zajmuje sztuki w ofercie, a ponowienie z tym samym kluczem nie zajmuje drugi raz', function () {
    $offer = stockAdapterOffer();

    stockAdapterReserve($offer, 2);
    stockAdapterReserve($offer, 2);

    expect(DB::table('offer_reservations')->where('offer_id', $offer->id)->where('status', 'active')->sum('quantity'))->toBe(2);
});

test('zwolnienie oddaje sztuki, a potwierdzenie zmniejsza stan', function () {
    $released = stockAdapterOffer();
    $confirmed = stockAdapterOffer();
    $port = app(StockReservations::class);
    stockAdapterReserve($released, 2);
    stockAdapterReserve($confirmed, 3);

    $port->release('order-1', OfferId::fromInt($released->id));
    $port->confirm('order-1', OfferId::fromInt($confirmed->id));

    expect(DB::table('offer_reservations')->where('offer_id', $released->id)->value('status'))->toBe('released')
        ->and((int) DB::table('offers')->where('id', $confirmed->id)->value('stock'))->toBe(2);
});

test('ReservationConflict (błąd programisty) leci dalej, nie staje się wynikiem', function () {
    $offer = stockAdapterOffer();
    stockAdapterReserve($offer, 2);
    app(StockReservations::class)->release('order-1', OfferId::fromInt($offer->id));

    expect(fn () => stockAdapterReserve($offer, 2))->toThrow(ReservationConflict::class);
});
