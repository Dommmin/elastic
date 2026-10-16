<?php

use App\Models\CartItem;
use App\Models\Offer as OfferRow;
use App\Models\Seller as SellerRow;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function checkoutOffer(SellerRow $seller, int $price = 10000, int $stock = 5): OfferRow
{
    return OfferRow::factory()->create(['seller_id' => $seller->id, 'price_cents' => $price, 'stock' => $stock]);
}

function checkoutSeller(): SellerRow
{
    return SellerRow::factory()->forUser(User::factory()->create())->create();
}

/**
 * @return array{lines: list<array{offer_id: int, quantity: int, unit_price_cents: int}>}
 */
function checkoutPayload(OfferRow ...$offers): array
{
    return ['lines' => array_map(
        fn (OfferRow $offer): array => ['offer_id' => $offer->id, 'quantity' => 2, 'unit_price_cents' => $offer->price_cents],
        $offers,
    )];
}

function checkoutFill(User $buyer, OfferRow ...$offers): void
{
    foreach ($offers as $offer) {
        CartItem::create(['user_id' => $buyer->id, 'offer_id' => $offer->id, 'quantity' => 2, 'added_price_cents' => $offer->price_cents]);
    }
}

test('checkout z dwoma sprzedawcami: dwa zamówienia, pusty koszyk, flash', function () {
    $buyer = User::factory()->create();
    $a = checkoutOffer(checkoutSeller());
    $b = checkoutOffer(checkoutSeller(), 2500);
    checkoutFill($buyer, $a, $b);

    $this->actingAs($buyer)->post(route('cart.checkout'), checkoutPayload($a, $b))
        ->assertRedirect('/orders')
        ->assertSessionHasNoErrors();

    expect(DB::table('orders')->where('buyer_user_id', $buyer->id)->pluck('status')->all())->toBe(['awaiting_payment', 'awaiting_payment'])
        ->and(CartItem::query()->count())->toBe(0)
        ->and(session('inertia.flash_data.toast.message'))->toBe('Złożono 2 zamówienia');
});

test('zmieniona cena oznacza pozycję i blokuje cały checkout', function () {
    $buyer = User::factory()->create();
    $a = checkoutOffer(checkoutSeller());
    $b = checkoutOffer(checkoutSeller());
    checkoutFill($buyer, $a, $b);
    $payload = checkoutPayload($a, $b);
    $b->update(['price_cents' => 11000]);

    $this->actingAs($buyer)->from(route('cart.index'))->post(route('cart.checkout'), $payload)
        ->assertRedirect(route('cart.index'))
        ->assertSessionHasErrors(['cart' => 'Ceny w koszyku się zmieniły — sprawdź i złóż zamówienie ponownie']);

    expect(DB::table('orders')->count())->toBe(0)
        ->and(CartItem::query()->count())->toBe(2);

    $this->actingAs($buyer)->get(route('cart.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('has_blockers', true)
            ->where('groups', fn ($groups) => collect($groups)->flatMap(fn ($g) => $g['lines'])->pluck('status', 'offer_id')->all() === [
                $a->id => 'ok',
                $b->id => 'price_changed',
            ]));
});

test('wycofana oferta oznacza pozycję jako niedostępną i blokuje checkout', function () {
    $buyer = User::factory()->create();
    $a = checkoutOffer(checkoutSeller());
    $b = checkoutOffer(checkoutSeller());
    checkoutFill($buyer, $a, $b);
    $payload = checkoutPayload($a, $b);
    $b->update(['status' => 'withdrawn']);

    $this->actingAs($buyer)->from(route('cart.index'))->post(route('cart.checkout'), $payload)
        ->assertSessionHasErrors(['cart' => 'Część ofert w koszyku nie jest już dostępna']);

    expect(DB::table('orders')->count())->toBe(0)
        ->and(CartItem::query()->count())->toBe(2);

    $this->actingAs($buyer)->get(route('cart.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('groups', fn ($groups) => collect($groups)->flatMap(fn ($g) => $g['lines'])->pluck('status', 'offer_id')->all() === [
                $a->id => 'ok',
                $b->id => 'unavailable',
            ]));
});

test('drugi checkout z pustym koszykiem przekierowuje bez błędu', function () {
    $buyer = User::factory()->create();
    $offer = checkoutOffer(checkoutSeller());
    checkoutFill($buyer, $offer);
    $payload = checkoutPayload($offer);

    $this->actingAs($buyer)->post(route('cart.checkout'), $payload)->assertRedirect('/orders');
    $this->actingAs($buyer)->post(route('cart.checkout'), $payload)
        ->assertRedirect('/orders')
        ->assertSessionHasNoErrors();

    expect(DB::table('orders')->count())->toBe(1);
});

test('linie niezgodne z koszykiem są odrzucone bez składania zamówień', function () {
    $buyer = User::factory()->create();
    $a = checkoutOffer(checkoutSeller());
    $b = checkoutOffer(checkoutSeller());
    checkoutFill($buyer, $a);
    $payload = checkoutPayload($a, $b);

    $this->actingAs($buyer)->post(route('cart.checkout'), $payload)
        ->assertSessionHasErrors('cart');

    expect(DB::table('orders')->count())->toBe(0)
        ->and(CartItem::query()->count())->toBe(1);
});

test('własna oferta w koszyku blokuje checkout', function () {
    $user = User::factory()->create();
    $offer = checkoutOffer(SellerRow::factory()->forUser($user)->create());
    checkoutFill($user, $offer);

    $this->actingAs($user)->post(route('cart.checkout'), checkoutPayload($offer))
        ->assertSessionHasErrors(['cart' => 'Nie możesz kupić własnej oferty']);

    expect(DB::table('orders')->count())->toBe(0);
});

test('ponad 20 ofert jednego sprzedawcy: komunikat w errors.cart, bez zamówień', function () {
    $buyer = User::factory()->create();
    $seller = checkoutSeller();
    $offers = array_map(fn (): OfferRow => checkoutOffer($seller), range(1, 21));
    checkoutFill($buyer, ...$offers);

    $this->actingAs($buyer)->post(route('cart.checkout'), checkoutPayload(...$offers))
        ->assertSessionHasErrors(['cart' => 'Tego koszyka nie da się złożyć — u jednego sprzedawcy zamówisz najwyżej 20 różnych ofert, wszystkie w jednej walucie']);

    expect(DB::table('orders')->count())->toBe(0)
        ->and(CartItem::query()->count())->toBe(21);
});

test('gość nie złoży zamówień', function () {
    $this->post(route('cart.checkout'), [])->assertRedirect(route('login'));
});

test('ten sam snapshot koszyka wysłany z dwóch kart tworzy zamówienia tylko raz', function () {
    $buyer = User::factory()->create();
    $a = checkoutOffer(checkoutSeller());
    $b = checkoutOffer(checkoutSeller());
    checkoutFill($buyer, $a, $b);
    $payload = checkoutPayload($a, $b);

    $this->actingAs($buyer)->post(route('cart.checkout'), $payload)->assertRedirect('/orders');
    $this->actingAs($buyer)->post(route('cart.checkout'), $payload)->assertRedirect('/orders')->assertSessionHasNoErrors();

    expect(DB::table('orders')->count())->toBe(2);
});
