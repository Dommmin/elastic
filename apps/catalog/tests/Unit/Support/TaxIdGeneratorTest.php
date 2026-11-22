<?php

use App\Support\TaxIdGenerator;
use SellerPortal\Domain\Seller\TaxId;

test('tysiąc kolejnych NIP-ów przechodzi walidację TaxId i jest unikalnych', function () {
    $nips = [];
    for ($n = 1; $n <= 1000; $n++) {
        $nip = TaxIdGenerator::forSeed($n);
        expect(TaxId::fromString($nip)->value())->toBe($nip);
        $nips[] = $nip;
    }

    expect(array_unique($nips))->toHaveCount(1000);
});

test('generator jest deterministyczny', function () {
    expect(TaxIdGenerator::forSeed(42))->toBe(TaxIdGenerator::forSeed(42))
        ->and(TaxIdGenerator::forSeed(42))->not->toBe(TaxIdGenerator::forSeed(43));
});
