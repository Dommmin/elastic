<?php

use App\Models\Outbox;
use App\Models\Product;
use App\Models\Review;
use App\Models\Seller;
use App\Models\User;

/**
 * Weryfikuje rdzeń wzorca Transactional Outbox z docs/05-SPOJNOSC-DANYCH.md:
 * atomowość zapisu (encja + zdarzenie w jednej transakcji), granularność
 * zdarzeń (osobny event na zmienione pole) i external versioning
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

/**
 * Mapa „zmienione pole -> zdarzenie” żyje tylko w teście: portal sprzedawcy
 * ma własne zdarzenia, a `Seller` zostaje najprostszym modelem z traitem.
 */
const SELLER_EVENTS = ['name' => 'seller.renamed', 'city' => 'seller.relocated'];

test('zmiana dwóch pól naraz emituje DWA osobne zdarzenia', function () {
    $seller = Seller::factory()->create(['name' => 'Sklep A', 'city' => 'Łódź']);

    $seller->updateWithOutbox(['name' => 'Sklep B', 'city' => 'Gdynia'], 'seller.updated', SELLER_EVENTS);

    $types = Outbox::pluck('event_type')->sort()->values()->all();

    expect($types)->toBe(['seller.relocated', 'seller.renamed']);
});

test('zmiana pola bez dedykowanego mapowania spada do zdarzenia domyślnego', function () {
    $seller = Seller::factory()->create(['rating' => 3]);

    $seller->updateWithOutbox(['rating' => 5], 'seller.updated', SELLER_EVENTS);

    expect(Outbox::pluck('event_type')->all())->toBe(['seller.updated']);
});

test('sequence rośnie monotonicznie z każdą aktualizacją tego samego agregatu', function () {
    $seller = Seller::factory()->create(['name' => 'Sklep A']);
    expect($seller->version)->toBe(1);

    $seller->updateWithOutbox(['name' => 'Sklep B'], 'seller.updated', SELLER_EVENTS);
    $seller->updateWithOutbox(['name' => 'Sklep C'], 'seller.updated', SELLER_EVENTS);

    $sequences = Outbox::orderBy('id')->pluck('sequence')->all();

    expect($sequences)->toBe([2, 3])
        ->and($seller->fresh()->version)->toBe(3);
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
    $seller = Seller::factory()->create();

    $seller->updateWithOutbox(['name' => 'Sklep B'], 'seller.updated', SELLER_EVENTS);
    $seller->updateWithOutbox(['name' => 'Sklep C'], 'seller.updated', SELLER_EVENTS);

    $eventIds = Outbox::pluck('event_id');

    expect($eventIds)->toHaveCount(2)
        ->and($eventIds->unique())->toHaveCount(2);
});
