<?php

use App\Models\CartItem;
use App\Models\Offer as OfferRow;
use App\Models\Outbox;
use App\Models\Product;
use App\Models\Seller as SellerRow;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** ETAP 13: `user.*` v2 niesie atrybucję do sprzedawcy (seller_id, seller_amounts). */
function sellerOffer(int $price = 10000): OfferRow
{
    $seller = SellerRow::factory()->forUser(User::factory()->create())->create();

    return OfferRow::factory()->create([
        'seller_id' => $seller->id,
        'product_id' => Product::factory()->create()->id,
        'price_cents' => $price,
        'stock' => 5,
    ]);
}

test('dodanie do koszyka niesie seller_id oferty i wersję 2 koperty', function () {
    $buyer = User::factory()->create();
    $offer = sellerOffer();

    $this->actingAs($buyer)->post(route('cart.items.store'), [
        'offer_id' => $offer->id, 'quantity' => 1, 'unit_price_cents' => $offer->price_cents,
    ])->assertSessionHasNoErrors();

    $row = Outbox::where('event_type', 'user.added_to_cart')->sole();
    expect($row->payload['seller_id'])->toBe($offer->seller_id)
        ->and($row->version)->toBe(2);
});

test('checkout liczy seller_amounts z linii koszyka per sprzedawca i zapisuje wersję 2', function () {
    $buyer = User::factory()->create();
    $a = sellerOffer(10000);
    $b = sellerOffer(2500);
    // druga oferta tego samego sprzedawcy co $a: kwoty się sumują
    $a2 = OfferRow::factory()->create([
        'seller_id' => $a->seller_id, 'product_id' => Product::factory()->create()->id, 'price_cents' => 700, 'stock' => 5,
    ]);
    foreach ([[$a, 2], [$b, 2], [$a2, 3]] as [$offer, $qty]) {
        CartItem::create(['user_id' => $buyer->id, 'offer_id' => $offer->id, 'quantity' => $qty, 'added_price_cents' => $offer->price_cents]);
    }

    $this->actingAs($buyer)->withUnencryptedCookie('mp_sid', (string) Str::uuid())->post(route('cart.checkout'), ['lines' => [
        ['offer_id' => $a->id, 'quantity' => 2, 'unit_price_cents' => 10000],
        ['offer_id' => $b->id, 'quantity' => 2, 'unit_price_cents' => 2500],
        ['offer_id' => $a2->id, 'quantity' => 3, 'unit_price_cents' => 700],
    ]])->assertRedirect('/orders');

    $row = Outbox::where('event_type', 'user.checked_out')->sole();
    expect($row->version)->toBe(2)
        ->and($row->payload['seller_amounts'])->toBe([
            (string) $a->seller_id => 2 * 10000 + 3 * 700,
            (string) $b->seller_id => 2 * 2500,
        ])
        ->and(array_sum($row->payload['seller_amounts']))->toBe($row->payload['amount_cents']);
});

test('checkout bez jednej oferty w odczycie analityki pomija tę linię, a nie gubi całego zdarzenia', function () {
    $buyer = User::factory()->create();
    $a = sellerOffer(10000);
    $b = sellerOffer(2500);
    foreach ([[$a, 1], [$b, 2]] as [$offer, $qty]) {
        CartItem::create(['user_id' => $buyer->id, 'offer_id' => $offer->id, 'quantity' => $qty, 'added_price_cents' => $offer->price_cents]);
    }
    // Zakres modelu ukrywa ofertę $b dopiero po zapisie zamówień, czyli tylko przed odczytem analityki.
    // Zakresy globalne są statyczne (przeżywają test), więc po żądaniu wyłączamy go flagą.
    $hide = false;
    DB::listen(function ($query) use (&$hide) {
        if (str_starts_with(strtolower($query->sql), 'insert into "orders"')) {
            $hide = true;
        }
    });
    OfferRow::addGlobalScope('test-ukryj-b', function ($query) use (&$hide, $b) {
        if ($hide) {
            $query->whereKeyNot($b->id);
        }
    });
    try {
        $this->actingAs($buyer)->withUnencryptedCookie('mp_sid', (string) Str::uuid())->post(route('cart.checkout'), ['lines' => [
            ['offer_id' => $a->id, 'quantity' => 1, 'unit_price_cents' => 10000],
            ['offer_id' => $b->id, 'quantity' => 2, 'unit_price_cents' => 2500],
        ]])->assertRedirect('/orders');
    } finally {
        $hide = false;
    }

    $row = Outbox::where('event_type', 'user.checked_out')->sole();
    expect($row->payload['seller_amounts'])->toBe([(string) $a->seller_id => 10000]);
});
