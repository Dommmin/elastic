<?php

use App\Models\Offer;
use App\Models\Product;
use App\Models\Seller;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Infrastructure\Persistence\SqlOfferRepository;
use SellerPortal\Infrastructure\SystemClock;

test('listed() = published i stock > 0', function () {
    $product = Product::factory()->create();
    $visible = Offer::factory()->for($product)->create(['stock' => 5]);
    Offer::factory()->for($product)->create(['stock' => 0]);
    Offer::factory()->for($product)->draft()->create(['stock' => 5]);
    Offer::factory()->for($product)->create(['stock' => 5, 'status' => 'suspended']);
    Offer::factory()->for($product)->withdrawn()->create(['stock' => 5]);
    Offer::factory()->for($product)->soldOut()->create();

    expect(Offer::listed()->pluck('id')->all())->toBe([$visible->id])
        ->and($product->listedOffers()->pluck('id')->all())->toBe([$visible->id]);
});

test('projekcja pomija ofertę wyprzedaną i zawieszoną', function () {
    $product = Product::factory()->create();
    $visible = Offer::factory()->for($product)->create(['stock' => 4, 'price_cents' => 1500]);
    Offer::factory()->for($product)->soldOut()->create(['price_cents' => 100]);
    Offer::factory()->for($product)->create(['stock' => 9, 'status' => 'suspended', 'price_cents' => 50]);

    $this->getJson("/api/internal/products/{$product->id}/projection")
        ->assertOk()
        ->assertJsonCount(1, 'offers')
        ->assertJsonPath('offers.0.offer_id', (string) $visible->id)
        ->assertJsonPath('price_min', 1500);
});

test('produkt bez widocznych ofert: offers [], price_min null, in_stock false', function () {
    $product = Product::factory()->create();
    Offer::factory()->for($product)->draft()->create(['stock' => 5]);

    $this->getJson("/api/internal/products/{$product->id}/projection")
        ->assertOk()
        ->assertJsonPath('offers', [])
        ->assertJsonPath('price_min', null)
        ->assertJsonPath('price_max', null)
        ->assertJsonPath('in_stock', false);
});

test('indeks nie pozwala na drugą żywą ofertę sprzedawcy na produkt, wycofana nie blokuje', function () {
    $product = Product::factory()->create();
    $seller = Seller::factory()->create();

    Offer::factory()->for($product)->for($seller)->withdrawn()->create();
    Offer::factory()->for($product)->for($seller)->create();

    expect(fn () => Offer::factory()->for($product)->for($seller)->draft()->create())
        ->toThrow(QueryException::class);

    // Inny sprzedawca na ten sam produkt i wycofane duplikaty są dozwolone.
    Offer::factory()->for($product)->create();
    Offer::factory()->for($product)->for($seller)->withdrawn()->create();

    expect(Offer::where('seller_id', $seller->id)->count())->toBe(3);
});

function readModelReserve(Offer $offer, int $quantity, string $status = 'active', string $key = 'order-1'): void
{
    DB::table('offer_reservations')->insert([
        'offer_id' => $offer->id,
        'reservation_key' => $key,
        'quantity' => $quantity,
        'status' => $status,
        'reserved_at' => '2026-10-16 12:00:00.000000',
        'settled_at' => $status === 'active' ? null : '2026-10-16 12:05:00.000000',
    ]);
}

test('listed() pomija ofertę z całym stanem zarezerwowanym', function () {
    $product = Product::factory()->create();
    $full = Offer::factory()->for($product)->create(['stock' => 3]);
    $partial = Offer::factory()->for($product)->create(['stock' => 3]);
    readModelReserve($full, 3);
    readModelReserve($partial, 2);

    expect(Offer::listed()->pluck('id')->all())->toBe([$partial->id])
        ->and(Offer::listed()->withAvailable()->first()->available)->toBe(1);
});

test('listed() liczy tylko aktywne rezerwacje', function () {
    $offer = Offer::factory()->create(['stock' => 3]);
    readModelReserve($offer, 3, 'released', 'order-1');
    readModelReserve($offer, 3, 'confirmed', 'order-2');

    expect(Offer::listed()->withAvailable()->first()->available)->toBe(3);
});

test('listed() i Offer::isListed() zgadzają się na tych samych danych', function (int $stock, int $reserved, string $status) {
    $row = Offer::factory()->create(['stock' => $stock, 'status' => $status, 'suspension_kind' => $status === 'suspended' ? 'seller_blocked' : null]);

    if ($reserved > 0) {
        readModelReserve($row, $reserved);
    }

    $domain = (new SqlOfferRepository(new SystemClock))->get(OfferId::fromInt($row->id));

    expect(Offer::listed()->whereKey($row->id)->exists())->toBe($domain->isListed());
})->with([
    'opublikowana, wolne sztuki' => [5, 2, 'published'],
    'opublikowana, wszystko zarezerwowane' => [5, 5, 'published'],
    'opublikowana, brak stanu' => [0, 0, 'published'],
    'opublikowana, bez rezerwacji' => [1, 0, 'published'],
    'zawieszona, wolne sztuki' => [5, 1, 'suspended'],
    'wycofana, wolne sztuki' => [5, 0, 'withdrawn'],
    'szkic, wolne sztuki' => [5, 0, 'draft'],
]);
