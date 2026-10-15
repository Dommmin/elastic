<?php

use SellerPortal\Domain\Offer\SuspensionKind;
use SellerPortal\Domain\Offer\SuspensionReason;
use SellerPortal\Domain\Shared\InvalidValue;
use SellerPortal\Domain\Shared\ModeratorId;
use SellerPortal\Domain\Shared\Reason;

test('restore odrzuca blokadę sprzedawcy z moderatorem lub notatką', function () {
    expect(fn () => SuspensionReason::restore(SuspensionKind::SellerBlocked, ModeratorId::fromInt(1), null))->toThrow(InvalidValue::class)
        ->and(fn () => SuspensionReason::restore(SuspensionKind::SellerBlocked, null, Reason::fromString('x')))->toThrow(InvalidValue::class);
});

test('restore odrzuca moderację bez moderatora albo bez notatki', function () {
    expect(fn () => SuspensionReason::restore(SuspensionKind::Moderation, null, Reason::fromString('x')))->toThrow(InvalidValue::class)
        ->and(fn () => SuspensionReason::restore(SuspensionKind::Moderation, ModeratorId::fromInt(1), null))->toThrow(InvalidValue::class);
});

test('restore przyjmuje dwie poprawne kombinacje', function () {
    expect(SuspensionReason::restore(SuspensionKind::SellerBlocked, null, null)->kind())->toBe(SuspensionKind::SellerBlocked)
        ->and(SuspensionReason::restore(SuspensionKind::Moderation, ModeratorId::fromInt(1), Reason::fromString('x'))->kind())->toBe(SuspensionKind::Moderation);
});
