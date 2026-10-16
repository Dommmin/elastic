<?php

use App\Http\DomainErrorMessages;
use Ordering\Application\Order\CannotBuyOwnOffer;
use Ordering\Application\Order\CartEmpty;
use Ordering\Application\Order\OfferNoLongerAvailable;
use Ordering\Application\Order\PriceChangedSinceCart;
use Ordering\Domain\InvalidOrderLines;
use Ordering\Domain\InvalidOrderTransition;
use Ordering\Domain\OrderStatus;
use SellerPortal\Application\Offer\OfferNotOwned;
use SellerPortal\Application\SellerAlreadyRegistered;
use SellerPortal\Domain\Offer\LiveOfferAlreadyExists;
use SellerPortal\Domain\Offer\NoPendingPriceChange;
use SellerPortal\Domain\Offer\OfferCannotBePublishedWithoutStock;
use SellerPortal\Domain\Offer\PendingPriceChangeMismatch;
use SellerPortal\Domain\Offer\PriceChangeAlreadyPending;
use SellerPortal\Domain\Offer\PriceChangeLimitExceeded;
use SellerPortal\Domain\Offer\StockBelowReserved;
use SellerPortal\Domain\Seller\SellerCannotSell;
use SharedKernel\ConcurrencyConflict;
use SharedKernel\DomainException;
use SharedKernel\InvalidValue;

test('każdy zmapowany CODE daje dokładny polski komunikat', function (DomainException $exception, string $message) {
    expect(DomainErrorMessages::for($exception))->toBe($message);
})->with([
    'price_change_limit' => [fn () => new PriceChangeLimitExceeded, 'Wykorzystano limit 5 zmian ceny na dobę'],
    'price_change_pending' => [fn () => new PriceChangeAlreadyPending, 'Ta oferta czeka na decyzję moderatora — anuluj zgłoszenie, aby zmienić cenę'],
    'concurrency_conflict' => [fn () => new ConcurrencyConflict, 'Ktoś w międzyczasie zmienił tę ofertę — odśwież stronę'],
    'live_offer_exists' => [fn () => new LiveOfferAlreadyExists, 'Masz już aktywną ofertę na ten produkt'],
    'offer_without_stock' => [fn () => new OfferCannotBePublishedWithoutStock, 'Nie można opublikować oferty bez towaru'],
    'seller_cannot_sell' => [fn () => new SellerCannotSell, 'Konto sprzedawcy nie jest aktywne'],
    'seller_already_registered' => [fn () => new SellerAlreadyRegistered, 'To konto ma już profil sprzedawcy'],
    'no_pending_price_change' => [fn () => new NoPendingPriceChange, 'Ta oferta nie ma już zgłoszonej zmiany ceny'],
    'pending_price_changed' => [fn () => new PendingPriceChangeMismatch, 'Sprzedawca zmienił zgłoszoną cenę — odśwież kolejkę'],
    'stock_below_reserved' => [fn () => new StockBelowReserved, 'Nie możesz zejść poniżej liczby zarezerwowanych sztuk'],
    'price_changed_since_cart' => [fn () => new PriceChangedSinceCart([]), 'Ceny w koszyku się zmieniły — sprawdź i złóż zamówienie ponownie'],
    'offer_no_longer_available' => [fn () => new OfferNoLongerAvailable([1]), 'Część ofert w koszyku nie jest już dostępna'],
    'cannot_buy_own_offer' => [fn () => new CannotBuyOwnOffer([1]), 'Nie możesz kupić własnej oferty'],
    'invalid_order_transition' => [fn () => InvalidOrderTransition::from(OrderStatus::Paid, 'ship'), 'Tej operacji nie można wykonać na zamówieniu w obecnym stanie'],
    'cart_empty' => [fn () => new CartEmpty, 'Koszyk jest pusty'],
    'invalid_order_lines (za dużo ofert)' => [fn () => InvalidOrderLines::tooMany(20), 'Tego koszyka nie da się złożyć — u jednego sprzedawcy zamówisz najwyżej 20 różnych ofert, wszystkie w jednej walucie'],
    'invalid_order_lines (dwie waluty)' => [fn () => InvalidOrderLines::mixedCurrencies(), 'Tego koszyka nie da się złożyć — u jednego sprzedawcy zamówisz najwyżej 20 różnych ofert, wszystkie w jednej walucie'],
]);

test('nieznany kod i brak CODE → komunikat zastępczy, nie treść wyjątku', function (DomainException $exception) {
    expect(DomainErrorMessages::for($exception))->toBe(DomainErrorMessages::FALLBACK)
        ->and(DomainErrorMessages::FALLBACK)->toBe('Operacja niedozwolona w obecnym stanie');
})->with([
    'invalid_value' => fn () => new InvalidValue('wewnętrzny szczegół'),
    'offer_not_owned' => fn () => new OfferNotOwned,
    'bez CODE' => fn () => new class('wewnętrzny szczegół') extends DomainException {},
]);

test('mapa nie ma kodów spoza testu powyżej', function () {
    $messages = (new ReflectionClassConstant(DomainErrorMessages::class, 'MESSAGES'))->getValue();

    expect(array_keys($messages))->toEqualCanonicalizing([
        'price_change_limit', 'price_change_pending', 'concurrency_conflict', 'live_offer_exists',
        'offer_without_stock', 'seller_cannot_sell', 'seller_already_registered', 'no_pending_price_change',
        'pending_price_changed', 'stock_below_reserved', 'price_changed_since_cart', 'offer_no_longer_available',
        'cannot_buy_own_offer', 'invalid_order_transition', 'cart_empty', 'invalid_order_lines',
    ]);
});
