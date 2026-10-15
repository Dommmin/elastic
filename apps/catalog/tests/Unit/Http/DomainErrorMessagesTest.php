<?php

use App\Http\DomainErrorMessages;
use SellerPortal\Application\Offer\OfferNotOwned;
use SellerPortal\Application\SellerAlreadyRegistered;
use SellerPortal\Domain\Offer\LiveOfferAlreadyExists;
use SellerPortal\Domain\Offer\NoPendingPriceChange;
use SellerPortal\Domain\Offer\OfferCannotBePublishedWithoutStock;
use SellerPortal\Domain\Offer\PriceChangeAlreadyPending;
use SellerPortal\Domain\Offer\PriceChangeLimitExceeded;
use SellerPortal\Domain\Seller\SellerCannotSell;
use SellerPortal\Domain\Shared\ConcurrencyConflict;
use SellerPortal\Domain\Shared\DomainException;
use SellerPortal\Domain\Shared\InvalidValue;

test('każdy zmapowany CODE daje dokładny polski komunikat', function (DomainException $exception, string $message) {
    expect(DomainErrorMessages::for($exception))->toBe($message);
})->with([
    'price_change_limit' => [fn () => new PriceChangeLimitExceeded, 'Wykorzystano limit 5 zmian ceny na dobę'],
    'price_change_pending' => [fn () => new PriceChangeAlreadyPending, 'Ta oferta czeka na decyzję moderatora — anuluj zgłoszenie, aby zmienić cenę'],
    'concurrency_conflict' => [fn () => new ConcurrencyConflict, 'Ktoś w międzyczasie zmienił tę ofertę — odśwież stronę'],
    'live_offer_exists' => [fn () => new LiveOfferAlreadyExists, 'Masz już aktywną ofertę na ten produkt'],
    'offer_without_stock' => [fn () => new OfferCannotBePublishedWithoutStock, 'Nie można opublikować oferty bez towaru'],
    'seller_cannot_sell' => [fn () => new SellerCannotSell, 'Twoje konto sprzedawcy nie jest aktywne'],
    'seller_already_registered' => [fn () => new SellerAlreadyRegistered, 'To konto ma już profil sprzedawcy'],
    'no_pending_price_change' => [fn () => new NoPendingPriceChange, 'Ta oferta nie ma już zgłoszonej zmiany ceny'],
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
    ]);
});
