<?php

use App\Models\Offer;
use App\Models\Outbox;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;

/**
 * Weryfikuje rdzeń wzorca Transactional Outbox z docs/05-SPOJNOSC-DANYCH.md:
 * atomowość zapisu (encja + zdarzenie w jednej transakcji), granularność
 * zdarzeń (osobny event na zmienione pole u Offer) i external versioning
 * (sequence rośnie monotonicznie z każdą zmianą agregatu).
 */
test('createWithOutbox zapisuje encję i dokładnie jedno zdarzenie w tej samej transakcji', function () {
    $product = Product::createWithOutbox([
        'name' => 'Telefon testowy',
        'ean' => '5901234123457',
    ], 'product.created');

    expect(Outbox::count())->toBe(1);

    $event = Outbox::first();
    expect($event->aggregate_type)->toBe('product')
        ->and($event->aggregate_id)->toBe((string) $product->id)
        ->and($event->event_type)->toBe('product.created')
        ->and($event->sequence)->toBe(1)
        ->and($event->published_at)->toBeNull()
        ->and($event->payload)->toHaveKey('name', 'Telefon testowy');
});

test('zmiana ceny I stanu magazynowego naraz emituje DWA osobne zdarzenia', function () {
    $offer = Offer::factory()->create(['price_cents' => 10000, 'stock' => 5]);

    $offer->updateWithOutbox(['price_cents' => 8900, 'stock' => 3], 'offer.updated');

    $types = Outbox::pluck('event_type')->sort()->values()->all();

    expect($types)->toBe(['offer.price_changed', 'offer.stock_changed']);
});

test('zmiana pola bez dedykowanego mapowania spada do zdarzenia domyślnego', function () {
    $offer = Offer::factory()->create(['shipping_days' => 3]);

    $offer->updateWithOutbox(['shipping_days' => 5], 'offer.updated');

    expect(Outbox::pluck('event_type')->all())->toBe(['offer.updated']);
});

test('sequence rośnie monotonicznie z każdą aktualizacją tego samego agregatu', function () {
    $offer = Offer::factory()->create(['price_cents' => 10000]);
    expect($offer->version)->toBe(1);

    $offer->updateWithOutbox(['price_cents' => 9000], 'offer.updated');
    $offer->updateWithOutbox(['price_cents' => 8000], 'offer.updated');

    $sequences = Outbox::orderBy('id')->pluck('sequence')->all();

    expect($sequences)->toBe([2, 3])
        ->and($offer->fresh()->version)->toBe(3);
});

test('deaktywacja oferty emituje offer.deactivated i ustawia active=false', function () {
    $offer = Offer::factory()->create(['active' => true]);

    $offer->deactivateWithOutbox();

    expect($offer->fresh()->active)->toBeFalse();
    expect(Outbox::first()->event_type)->toBe('offer.deactivated');
});

test('modele create-only (Review) nie wymagają kolumny version', function () {
    $product = Product::factory()->create();
    $user = User::factory()->create();

    $review = Review::createWithOutbox([
        'product_id' => $product->id,
        'user_id' => $user->id,
        'rating' => 5,
        'body' => 'Świetny produkt',
    ], 'review.created');

    expect($review->exists)->toBeTrue();

    $event = Outbox::first();
    expect($event->event_type)->toBe('review.created')
        ->and($event->sequence)->toBe(1);
});

test('każde zdarzenie ma unikalny event_id (ULID) — klucz idempotencji konsumenta', function () {
    $offer = Offer::factory()->create();

    $offer->updateWithOutbox(['stock' => 1], 'offer.updated');
    $offer->updateWithOutbox(['stock' => 2], 'offer.updated');

    $eventIds = Outbox::pluck('event_id');

    expect($eventIds)->toHaveCount(2)
        ->and($eventIds->unique())->toHaveCount(2);
});
