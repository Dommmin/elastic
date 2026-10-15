<?php

use App\Models\Offer;
use App\Models\Seller;
use App\Models\User;

beforeEach(function () {
    // Joby polityk idą normalnie na połączenie `database`; `sync` wykonuje je
    // od razu, więc test widzi skutek blokady w tym samym żądaniu.
    config(['queue.seller_policies_connection' => 'sync']);
});

/**
 * @return list<string>
 */
function sellerBlockingTestProjectionOfferIds(int $productId): array
{
    return test()->getJson("/api/internal/products/{$productId}/projection")->json('offers.*.offer_id') ?? [];
}

test('blokada sprzedawcy zawiesza jego opublikowane oferty', function () {
    $seller = Seller::factory()->forUser(User::factory()->create())->create();
    $published = Offer::factory()->for($seller)->count(2)->create(['stock' => 3]);
    $draft = Offer::factory()->for($seller)->draft()->create();
    $foreign = Offer::factory()->create(['stock' => 3]);

    $this->actingAs(User::factory()->admin()->create())
        ->from('/panel/sellers')
        ->post("/panel/sellers/{$seller->id}/block", ['reason' => 'Skargi klientów'])
        ->assertSessionHasNoErrors();

    foreach ($published as $offer) {
        expect($offer->fresh())
            ->status->toBe('suspended')
            ->suspension_kind->toBe('seller_blocked')
            ->and(sellerBlockingTestProjectionOfferIds($offer->product_id))->toBe([]);
    }

    expect($draft->fresh()->status)->toBe('draft')
        ->and($foreign->fresh()->status)->toBe('published')
        ->and(sellerBlockingTestProjectionOfferIds($foreign->product_id))->toBe([(string) $foreign->id]);
});

test('odblokowanie przywraca tylko zawieszone przez blokadę', function () {
    $seller = Seller::factory()->forUser(User::factory()->create())->create();
    $byBlock = Offer::factory()->for($seller)->create(['stock' => 3]);
    $byModerator = Offer::factory()->for($seller)->create(['stock' => 3]);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post("/panel/offers/{$byModerator->id}/suspend", ['note' => 'Podróbka'])
        ->assertSessionHasNoErrors();
    $this->actingAs($admin)
        ->post("/panel/sellers/{$seller->id}/block", ['reason' => 'Skargi klientów'])
        ->assertSessionHasNoErrors();
    expect($byBlock->fresh()->status)->toBe('suspended');

    $this->actingAs($admin)
        ->post("/panel/sellers/{$seller->id}/unblock")
        ->assertSessionHasNoErrors();

    expect($byBlock->fresh()->status)->toBe('published')
        ->and(sellerBlockingTestProjectionOfferIds($byBlock->product_id))->toBe([(string) $byBlock->id])
        ->and($byModerator->fresh())
        ->status->toBe('suspended')
        ->suspension_kind->toBe('moderation')
        ->and(sellerBlockingTestProjectionOfferIds($byModerator->product_id))->toBe([]);
});
