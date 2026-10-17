<?php

use App\Models\CartItem;
use App\Models\Offer as OfferRow;
use App\Models\Seller as SellerRow;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Brak towaru przy składaniu: jedyny przypadek HTTP, w którym gałęzie
 * `zamowienia` (saga) i `zamowienia-ddd-lite` (jedna transakcja) zachowują się
 * celowo inaczej, więc nie siedzi we wspólnym katalogu Http. Saga przyjmuje
 * zamówienie i dopiero rezerwacja je odrzuca: kupujący widzi zamówienie
 * „Odrzucone” z powodem. Na lite ten sam plik opisuje odmowę w koszyku.
 * Porównanie: docs/blog/saga-vs-transakcja.md.
 */

test('brak towaru: zamówienie powstaje, rezerwacja je odrzuca, kupujący widzi powód', function () {
    $buyer = User::factory()->create();
    $offer = OfferRow::factory()->create([
        'seller_id' => SellerRow::factory()->forUser(User::factory()->create())->create()->id,
        'price_cents' => 10000,
        'stock' => 1,
    ]);
    CartItem::create(['user_id' => $buyer->id, 'offer_id' => $offer->id, 'quantity' => 2, 'added_price_cents' => 10000]);

    $this->actingAs($buyer)->post(route('cart.checkout'), ['lines' => [
        ['offer_id' => $offer->id, 'quantity' => 2, 'unit_price_cents' => 10000],
    ]])->assertRedirect('/orders')->assertSessionHasNoErrors();

    $id = (int) DB::table('orders')->where('buyer_user_id', $buyer->id)->value('id');
    $name = DB::table('order_lines')->where('order_id', $id)->value('product_name');

    expect(CartItem::query()->count())->toBe(0)
        ->and(DB::table('offer_reservations')->where('status', 'active')->count())->toBe(0);

    $this->actingAs($buyer)->get(route('orders.show', $id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('order.status', 'rejected')
            ->where('order.status_label', 'Odrzucone')
            ->where('order.rejection_label', 'Zabrakło towaru: '.$name)
            ->where('actions', []));
});
