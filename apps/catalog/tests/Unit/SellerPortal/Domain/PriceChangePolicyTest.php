<?php

use SellerPortal\Domain\Offer\PriceChangeDecision;
use SellerPortal\Domain\Offer\PriceChangeLimitExceeded;
use SellerPortal\Domain\Offer\PriceChangePolicy;
use SellerPortal\Domain\Shared\Money;

function priceChangePolicyNow(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-02-01 10:00:00');
}

/** @return list<DateTimeImmutable> */
function priceChangePolicyHoursAgo(int ...$hours): array
{
    return array_map(static fn (int $h): DateTimeImmutable => priceChangePolicyNow()->modify("-{$h} hours"), $hours);
}

test('zmiana o 30% wchodzi od razu, o 30,01% czeka', function () {
    $now = priceChangePolicyNow();

    expect(PriceChangePolicy::decide(Money::fromCents(10000), Money::fromCents(13000), [], $now))->toBe(PriceChangeDecision::AppliedNow)
        ->and(PriceChangePolicy::decide(Money::fromCents(10000), Money::fromCents(13001), [], $now))->toBe(PriceChangeDecision::AwaitingApproval);
});

test('piąta zmiana jeszcze przechodzi, szósta w 24 h rzuca PriceChangeLimitExceeded', function () {
    $now = priceChangePolicyNow();
    $price = [Money::fromCents(10000), Money::fromCents(10100)];

    expect(PriceChangePolicy::decide(...[...$price, priceChangePolicyHoursAgo(1, 2, 3, 4), $now]))->toBe(PriceChangeDecision::AppliedNow)
        ->and(fn () => PriceChangePolicy::decide(...[...$price, priceChangePolicyHoursAgo(1, 2, 3, 4, 5), $now]))->toThrow(PriceChangeLimitExceeded::class);
});

test('limit ma pierwszeństwo przed progiem', function () {
    expect(fn () => PriceChangePolicy::decide(Money::fromCents(10000), Money::fromCents(90000), priceChangePolicyHoursAgo(1, 2, 3, 4, 5), priceChangePolicyNow()))
        ->toThrow(PriceChangeLimitExceeded::class);
});

test('okno jest otwarte z lewej: zmiana dokładnie 24 h temu się nie liczy, 24 h minus 1 s tak', function () {
    $now = priceChangePolicyNow();
    $exact = $now->modify('-24 hours');
    $inside = $now->modify('-24 hours')->modify('+1 second');

    expect(PriceChangePolicy::withinWindow([$exact], $now))->toBe([])
        ->and(PriceChangePolicy::withinWindow([$inside], $now))->toHaveCount(1)
        ->and(PriceChangePolicy::withinWindow([$now->modify('-24 hours -1 second')], $now))->toBe([]);
});

test('changesLeft = 5 − zmiany w oknie, min 0', function () {
    $now = priceChangePolicyNow();

    expect(PriceChangePolicy::changesLeft([], $now))->toBe(5)
        ->and(PriceChangePolicy::changesLeft(priceChangePolicyHoursAgo(1, 2), $now))->toBe(3)
        ->and(PriceChangePolicy::changesLeft(priceChangePolicyHoursAgo(1, 2, 3, 4, 5, 6, 30), $now))->toBe(0)
        ->and(PriceChangePolicy::changesLeft(priceChangePolicyHoursAgo(25, 30), $now))->toBe(5);
});

test('PriceChangeLimitExceeded ma stabilny kod', function () {
    expect(PriceChangeLimitExceeded::CODE)->toBe('price_change_limit');
});
