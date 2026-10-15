<?php

use SellerPortal\Domain\Shared\Currency;
use SellerPortal\Domain\Shared\CurrencyMismatch;
use SellerPortal\Domain\Shared\InvalidValue;
use SellerPortal\Domain\Shared\Money;

test('Money odrzuca 0 i ujemne', function () {
    expect(fn () => Money::fromCents(0))->toThrow(InvalidValue::class)
        ->and(fn () => Money::fromCents(-1))->toThrow(InvalidValue::class);
});

test('Money domyślnie jest w PLN i zna swoje grosze', function () {
    $money = Money::fromCents(12999);

    expect($money->cents())->toBe(12999)
        ->and($money->currency())->toBe(Currency::PLN);
});

test('equals porównuje kwotę i walutę', function () {
    expect(Money::fromCents(100)->equals(Money::fromCents(100)))->toBeTrue()
        ->and(Money::fromCents(100)->equals(Money::fromCents(101)))->toBeFalse()
        ->and(Money::fromCents(100)->equals(Money::fromCents(100, Currency::EUR)))->toBeFalse();
});

test('30% to jeszcze nie „więcej niż 30%”', function () {
    expect(Money::fromCents(10000)->differsByMoreThanPercent(Money::fromCents(13000), 30))->toBeFalse()
        ->and(Money::fromCents(10000)->differsByMoreThanPercent(Money::fromCents(13001), 30))->toBeTrue()
        ->and(Money::fromCents(10000)->differsByMoreThanPercent(Money::fromCents(7000), 30))->toBeFalse()
        ->and(Money::fromCents(10000)->differsByMoreThanPercent(Money::fromCents(6999), 30))->toBeTrue();
});

test('porównanie walut PLN i EUR rzuca CurrencyMismatch', function () {
    $pln = Money::fromCents(10000);
    $eur = Money::fromCents(10000, Currency::EUR);

    expect(fn () => $pln->differsByMoreThanPercent($eur, 30))->toThrow(CurrencyMismatch::class);
});

test('kody wyjątków domeny są stałe', function () {
    expect(InvalidValue::CODE)->toBe('invalid_value')
        ->and(CurrencyMismatch::CODE)->toBe('currency_mismatch');
});
