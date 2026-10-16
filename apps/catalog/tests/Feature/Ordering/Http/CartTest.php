<?php

use App\Models\CartItem;
use App\Models\Offer as OfferRow;
use App\Models\Seller as SellerRow;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function cartOffer(array $attributes = [], ?SellerRow $seller = null): OfferRow
{
    return OfferRow::factory()->create([
        'seller_id' => ($seller ?? SellerRow::factory()->forUser(User::factory()->create())->create())->id,
        'price_cents' => 10000,
        'stock' => 5,
        ...$attributes,
    ]);
}

function cartQuantity(User $user, OfferRow $offer): ?int
{
    return CartItem::query()->where('user_id', $user->id)->where('offer_id', $offer->id)->value('quantity');
}

test('dodanie oferty zapisuje pozycję z ceną z chwili dodania', function () {
    $buyer = User::factory()->create();
    $offer = cartOffer();

    $this->actingAs($buyer)->post(route('cart.items.store'), ['offer_id' => $offer->id, 'quantity' => 2, 'unit_price_cents' => 10000])
        ->assertSessionHasNoErrors();

    expect(cartQuantity($buyer, $offer))->toBe(2)
        ->and(CartItem::query()->value('added_price_cents'))->toBe(10000);
});

test('dodanie tej samej oferty dwa razy sumuje ilość', function () {
    $buyer = User::factory()->create();
    $offer = cartOffer();

    $this->actingAs($buyer)->post(route('cart.items.store'), ['offer_id' => $offer->id, 'quantity' => 2, 'unit_price_cents' => 10000]);
    $this->actingAs($buyer)->post(route('cart.items.store'), ['offer_id' => $offer->id, 'quantity' => 3, 'unit_price_cents' => 10000])
        ->assertSessionHasNoErrors();

    expect(cartQuantity($buyer, $offer))->toBe(5)
        ->and(CartItem::query()->count())->toBe(1);
});

test('ilość ponad min(10, dostępne) to błąd walidacji, koszyk bez zmian', function (int $stock, int $first, int $second) {
    $buyer = User::factory()->create();
    $offer = cartOffer(['stock' => $stock]);

    $this->actingAs($buyer)->post(route('cart.items.store'), ['offer_id' => $offer->id, 'quantity' => $first, 'unit_price_cents' => 10000])
        ->assertSessionHasNoErrors();
    $this->actingAs($buyer)->post(route('cart.items.store'), ['offer_id' => $offer->id, 'quantity' => $second, 'unit_price_cents' => 10000])
        ->assertSessionHasErrors('quantity');

    expect(cartQuantity($buyer, $offer))->toBe($first);
})->with([
    'ponad dostępne' => [5, 3, 3],
    'ponad limit 10 przy dużym stanie' => [50, 8, 3],
]);

test('pojedyncze dodanie ponad limit jest odrzucone i nic nie zapisuje', function () {
    $buyer = User::factory()->create();
    $offer = cartOffer(['stock' => 50]);

    $this->actingAs($buyer)->post(route('cart.items.store'), ['offer_id' => $offer->id, 'quantity' => 11, 'unit_price_cents' => 10000])
        ->assertSessionHasErrors('quantity');

    expect(CartItem::query()->count())->toBe(0);
});

test('oferta niewidoczna nie trafia do koszyka', function (string $state) {
    $buyer = User::factory()->create();
    $offer = OfferRow::factory()->{$state}()->create();

    $this->actingAs($buyer)->post(route('cart.items.store'), ['offer_id' => $offer->id, 'quantity' => 1, 'unit_price_cents' => $offer->price_cents])
        ->assertSessionHasErrors('offer_id');

    expect(CartItem::query()->count())->toBe(0);
})->with(['draft', 'withdrawn', 'soldOut']);

test('sprzedawca nie doda do koszyka własnej oferty', function () {
    $user = User::factory()->create();
    $offer = cartOffer(seller: SellerRow::factory()->forUser($user)->create());

    $this->actingAs($user)->post(route('cart.items.store'), ['offer_id' => $offer->id, 'quantity' => 1, 'unit_price_cents' => $offer->price_cents])
        ->assertSessionHasErrors(['offer_id' => 'Nie możesz kupić własnej oferty']);

    expect(CartItem::query()->count())->toBe(0);
});

test('koszyk grupuje pozycje per sprzedawca', function () {
    $buyer = User::factory()->create();
    $sellerA = SellerRow::factory()->forUser(User::factory()->create())->create(['name' => 'Alfa']);
    $sellerB = SellerRow::factory()->forUser(User::factory()->create())->create(['name' => 'Beta']);
    $a1 = cartOffer(['price_cents' => 1000], $sellerA);
    $a2 = cartOffer(['price_cents' => 2000], $sellerA);
    $b1 = cartOffer(['price_cents' => 5000], $sellerB);
    foreach ([[$a1, 2], [$a2, 1], [$b1, 3]] as [$offer, $quantity]) {
        CartItem::create(['user_id' => $buyer->id, 'offer_id' => $offer->id, 'quantity' => $quantity, 'added_price_cents' => $offer->price_cents]);
    }

    $this->actingAs($buyer)->get(route('cart.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('shop/Cart')
            ->has('groups', 2)
            ->where('groups.0.seller.name', 'Alfa')
            ->where('groups.0.subtotal_cents', 4000)
            ->has('groups.0.lines', 2)
            ->where('groups.0.lines.0.status', 'ok')
            ->where('groups.1.seller.name', 'Beta')
            ->where('groups.1.subtotal_cents', 15000)
            ->where('groups.1.lines.0.available', 5));
});

test('koszyk oznacza zmienioną cenę i niedostępną ofertę', function () {
    $buyer = User::factory()->create();
    $seller = SellerRow::factory()->forUser(User::factory()->create())->create();
    $changed = cartOffer(['price_cents' => 12000], $seller);
    $gone = cartOffer([], $seller);
    CartItem::create(['user_id' => $buyer->id, 'offer_id' => $changed->id, 'quantity' => 1, 'added_price_cents' => 10000]);
    CartItem::create(['user_id' => $buyer->id, 'offer_id' => $gone->id, 'quantity' => 1, 'added_price_cents' => 10000]);
    $gone->update(['status' => 'withdrawn']);

    $this->actingAs($buyer)->get(route('cart.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('has_blockers', true)
            ->where('groups.0.lines', fn ($lines) => collect($lines)->pluck('status', 'offer_id')->all() === [
                $changed->id => 'price_changed',
                $gone->id => 'unavailable',
            ]));
});

test('zmiana ilości w koszyku respektuje limit', function () {
    $buyer = User::factory()->create();
    $offer = cartOffer();
    CartItem::create(['user_id' => $buyer->id, 'offer_id' => $offer->id, 'quantity' => 1, 'added_price_cents' => 10000]);

    $this->actingAs($buyer)->patch(route('cart.items.update', $offer->id), ['quantity' => 4])->assertSessionHasNoErrors();
    expect(cartQuantity($buyer, $offer))->toBe(4);

    $this->actingAs($buyer)->patch(route('cart.items.update', $offer->id), ['quantity' => 6])->assertSessionHasErrors('quantity');
    expect(cartQuantity($buyer, $offer))->toBe(4);
});

test('zaakceptowanie nowej ceny odświeża cenę widzianą przez kupującego', function () {
    $buyer = User::factory()->create();
    $offer = cartOffer(['price_cents' => 12000]);
    CartItem::create(['user_id' => $buyer->id, 'offer_id' => $offer->id, 'quantity' => 2, 'added_price_cents' => 10000]);

    $this->actingAs($buyer)->patch(route('cart.items.update', $offer->id), ['accept_price_cents' => 12000])->assertSessionHasNoErrors();

    expect(CartItem::query()->value('added_price_cents'))->toBe(12000)
        ->and(cartQuantity($buyer, $offer))->toBe(2);
});

test('ponowne dodanie po zmianie ceny jest odrzucone, a pozycja zostaje price_changed', function () {
    $buyer = User::factory()->create();
    $offer = cartOffer();
    $this->actingAs($buyer)->post(route('cart.items.store'), ['offer_id' => $offer->id, 'quantity' => 1, 'unit_price_cents' => 10000]);
    $offer->update(['price_cents' => 12000]);

    // Strona produktu pokazywała starą cenę: dodanie odrzucone.
    $this->actingAs($buyer)->post(route('cart.items.store'), ['offer_id' => $offer->id, 'quantity' => 1, 'unit_price_cents' => 10000])
        ->assertSessionHasErrors('offer_id');
    // Nawet po zobaczeniu nowej ceny dodanie nie kasuje oznaczenia zmiany.
    $this->actingAs($buyer)->post(route('cart.items.store'), ['offer_id' => $offer->id, 'quantity' => 1, 'unit_price_cents' => 12000])
        ->assertSessionHasNoErrors();

    expect(cartQuantity($buyer, $offer))->toBe(2)
        ->and(CartItem::query()->value('added_price_cents'))->toBe(10000);
    $this->actingAs($buyer)->get(route('cart.index'))
        ->assertInertia(fn (Assert $page) => $page->where('groups.0.lines.0.status', 'price_changed'));
});

test('akceptacja nieaktualnej ceny jest odrzucona', function () {
    $buyer = User::factory()->create();
    $offer = cartOffer(['price_cents' => 13000]);
    CartItem::create(['user_id' => $buyer->id, 'offer_id' => $offer->id, 'quantity' => 1, 'added_price_cents' => 10000]);

    $this->actingAs($buyer)->patch(route('cart.items.update', $offer->id), ['accept_price_cents' => 12000])
        ->assertSessionHasErrors('accept_price_cents');

    expect(CartItem::query()->value('added_price_cents'))->toBe(10000);
});

test('usunięcie pozycji i cudza pozycja', function () {
    $buyer = User::factory()->create();
    $other = User::factory()->create();
    $offer = cartOffer();
    CartItem::create(['user_id' => $buyer->id, 'offer_id' => $offer->id, 'quantity' => 1, 'added_price_cents' => 10000]);

    $this->actingAs($other)->delete(route('cart.items.destroy', $offer->id))->assertNotFound();
    expect(CartItem::query()->count())->toBe(1);

    $this->actingAs($buyer)->delete(route('cart.items.destroy', $offer->id))->assertRedirect();
    expect(CartItem::query()->count())->toBe(0);
});

test('gość dostaje przekierowanie do logowania', function () {
    $this->get(route('cart.index'))->assertRedirect(route('login'));
    $this->post(route('cart.items.store'), [])->assertRedirect(route('login'));
    $this->post(route('cart.checkout'), [])->assertRedirect(route('login'));
});

test('współdzielone propsy niosą licznik koszyka', function () {
    $buyer = User::factory()->create();
    $offer = cartOffer();
    CartItem::create(['user_id' => $buyer->id, 'offer_id' => $offer->id, 'quantity' => 3, 'added_price_cents' => 10000]);

    $this->actingAs($buyer)->get(route('cart.index'))
        ->assertInertia(fn (Assert $page) => $page->where('cart.count', 1));
});

test('gość ma licznik koszyka równy zero', function () {
    $this->get(route('products.show', OfferRow::factory()->create()->product_id))
        ->assertInertia(fn (Assert $page) => $page->where('cart.count', 0));
});
