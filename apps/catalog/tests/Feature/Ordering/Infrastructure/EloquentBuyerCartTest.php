<?php

use App\Models\CartItem;
use App\Models\Offer as OfferRow;
use App\Models\Seller as SellerRow;
use App\Models\User;
use Ordering\Domain\BuyerId;
use Ordering\Domain\OfferId;
use Ordering\Infrastructure\EloquentBuyerCart;

test('czyści tylko złożone oferty: pozycja dodana w innej karcie i cudzy koszyk zostają', function () {
    $buyer = User::factory()->create();
    $other = User::factory()->create();
    $seller = SellerRow::factory()->forUser(User::factory()->create())->create();
    [$ordered, $addedMeanwhile] = OfferRow::factory()->count(2)->create(['seller_id' => $seller->id])->all();

    foreach ([[$buyer, $ordered], [$buyer, $addedMeanwhile], [$other, $ordered]] as [$user, $offer]) {
        CartItem::query()->create(['user_id' => $user->id, 'offer_id' => $offer->id, 'quantity' => 1, 'added_price_cents' => 1000]);
    }

    (new EloquentBuyerCart)->clear(BuyerId::fromInt($buyer->id), [OfferId::fromInt($ordered->id)]);

    expect(CartItem::query()->where('user_id', $buyer->id)->pluck('offer_id')->all())->toBe([$addedMeanwhile->id])
        ->and(CartItem::query()->where('user_id', $other->id)->pluck('offer_id')->all())->toBe([$ordered->id]);
});
