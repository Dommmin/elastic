<?php

use SellerPortal\Domain\Seller\TaxId;
use SharedKernel\InvalidValue;

test('przyjmuje NIP z myślnikami i zwraca 10 cyfr', function () {
    expect(TaxId::fromString('526-104-08-28')->value())->toBe('5261040828')
        ->and(TaxId::fromString('526 104 08 28')->value())->toBe('5261040828')
        ->and(TaxId::fromString('5261040828')->value())->toBe('5261040828');
});

test('odrzuca złą sumę kontrolną, zły rozmiar i nie-cyfry', function (string $nip) {
    expect(fn () => TaxId::fromString($nip))->toThrow(InvalidValue::class);
})->with([
    'zła suma' => ['5261040827'],
    'za krótki' => ['123'],
    'litery' => ['abcdefghij'],
    'za długi' => ['52610408280'],
    'pusty' => [''],
]);
