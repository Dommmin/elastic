<?php

use App\Models\Offer;
use App\Models\Outbox;
use App\Models\Product;
use App\Models\User;

function adminOffer(array $attrs = []): Offer
{
    return Offer::factory()->create(array_merge(['price_cents' => 1000, 'stock' => 3, 'active' => true], $attrs));
}

test('cena i stan naraz dają dwa zdarzenia', function () {
    $offer = adminOffer();

    $this->actingAs(User::factory()->admin()->create())
        ->put("/panel/offers/{$offer->id}", ['price' => '19.99', 'stock' => 5])
        ->assertRedirect(route('panel.products.edit', $offer->product_id));

    expect(Outbox::where('event_type', 'offer.price_changed')->count())->toBe(1)
        ->and(Outbox::where('event_type', 'offer.stock_changed')->count())->toBe(1)
        ->and($offer->fresh()->price_cents)->toBe(1999)
        ->and($offer->fresh()->stock)->toBe(5);
});

test('cena z przecinkiem jest akceptowana', function () {
    $offer = adminOffer();

    $this->actingAs(User::factory()->admin()->create())
        ->put("/panel/offers/{$offer->id}", ['price' => '19,99', 'stock' => 3])
        ->assertSessionHasNoErrors();

    expect($offer->fresh()->price_cents)->toBe(1999);
});

test('cena 0 i ujemny stan są odrzucane', function () {
    $offer = adminOffer();

    $this->actingAs(User::factory()->admin()->create())
        ->put("/panel/offers/{$offer->id}", ['price' => '0', 'stock' => -1])
        ->assertSessionHasErrors(['price', 'stock']);

    expect(Outbox::count())->toBe(0);
});

test('zapis bez zmian nie daje zdarzenia', function () {
    $offer = adminOffer();
    $version = $offer->version;

    $this->actingAs(User::factory()->admin()->create())
        ->put("/panel/offers/{$offer->id}", ['price' => '10,00', 'stock' => 3])
        ->assertRedirect();

    expect(Outbox::count())->toBe(0)->and($offer->fresh()->version)->toBe($version);
});

test('dezaktywacja daje offer.deactivated', function () {
    $offer = adminOffer();

    $this->actingAs(User::factory()->admin()->create())
        ->post("/panel/offers/{$offer->id}/deactivate")
        ->assertRedirect(route('panel.products.edit', $offer->product_id));

    expect($offer->fresh()->active)->toBeFalse()
        ->and(Outbox::where('event_type', 'offer.deactivated')->count())->toBe(1);
});

test('ponowna dezaktywacja nie daje zdarzenia', function () {
    $offer = adminOffer(['active' => false]);

    $this->actingAs(User::factory()->admin()->create())
        ->followingRedirects()
        ->from("/panel/products/{$offer->product_id}")
        ->post("/panel/offers/{$offer->id}/deactivate")
        ->assertInertia(fn ($page) => $page->hasFlash('status', 'Oferta już nieaktywna'));

    expect(Outbox::count())->toBe(0);
});

test('redirect wraca na stronę produktu oferty', function () {
    $a = Product::factory()->create();
    $b = Product::factory()->create();
    $offer = adminOffer(['product_id' => $b->id]);

    $this->actingAs(User::factory()->admin()->create())
        ->from("/panel/products/{$a->id}")
        ->put("/panel/offers/{$offer->id}", ['price' => '12', 'stock' => 1])
        ->assertRedirect(route('panel.products.edit', $b));
});

test('strona edycji produktu zwraca oferty posortowane po id', function () {
    $product = Product::factory()->create();
    $second = adminOffer(['product_id' => $product->id]);
    $first = adminOffer(['product_id' => $product->id]);

    $this->actingAs(User::factory()->admin()->create())
        ->get("/panel/products/{$product->id}")
        ->assertInertia(fn ($page) => $page
            ->has('offers', 2)
            ->where('offers.0.id', $second->id)
            ->where('offers.1.id', $first->id)
            ->has('offers.0', fn ($o) => $o->hasAll(['id', 'seller', 'price_cents', 'stock', 'active', 'version'])));
});

test('gość nie może zapisać oferty', function () {
    $offer = adminOffer();

    $this->put("/panel/offers/{$offer->id}", ['price' => '12', 'stock' => 1])->assertRedirect('/login');
    $this->post("/panel/offers/{$offer->id}/deactivate")->assertRedirect('/login');
    $this->actingAs(User::factory()->create())->put("/panel/offers/{$offer->id}", [])->assertForbidden();

    expect(Outbox::count())->toBe(0);
});
