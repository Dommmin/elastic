<?php

use App\Models\Offer;
use App\Models\Seller;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

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
        ->post("/panel/offers/{$byModerator->id}/suspend", ['note' => 'Podróbka', 'version' => 1])
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

test('moderator nie przywróci oferty zablokowanego sprzedawcy: brak przycisku, błąd domain, projekcja bez zmian', function () {
    $seller = Seller::factory()->forUser(User::factory()->create())->create();
    $offer = Offer::factory()->for($seller)->create(['stock' => 3]);
    $admin = User::factory()->admin()->create();
    $edit = "/panel/products/{$offer->product_id}";

    $this->actingAs($admin)
        ->post("/panel/sellers/{$seller->id}/block", ['reason' => 'Skargi klientów'])
        ->assertSessionHasNoErrors();
    expect($offer->fresh())
        ->status->toBe('suspended')
        ->suspension_kind->toBe('seller_blocked');

    // Agregat oferty sam pozwoliłby na „Przywróć”; panel go nie pokazuje, bo sprzedawca nie może sprzedawać.
    $this->actingAs($admin)
        ->get($edit)
        ->assertInertia(fn (Assert $page) => $page
            ->where('offers.0.seller_status', 'blocked')
            ->where('offers.0.allowed_actions', []));

    $this->actingAs($admin)
        ->from($edit)
        ->post("/panel/offers/{$offer->id}/reinstate", ['version' => $offer->fresh()->version])
        ->assertRedirect($edit)
        ->assertSessionHasErrors(['domain' => 'Konto sprzedawcy nie jest aktywne']);

    expect($offer->fresh()->status)->toBe('suspended')
        ->and(sellerBlockingTestProjectionOfferIds($offer->product_id))->toBe([]);

    // Po odblokowaniu ofertę przywraca polityka, a ręcznie zawieszone oferty znów mają „Przywróć”.
    $this->actingAs($admin)->post("/panel/sellers/{$seller->id}/unblock")->assertSessionHasNoErrors();
    expect($offer->fresh()->status)->toBe('published')
        ->and(sellerBlockingTestProjectionOfferIds($offer->product_id))->toBe([(string) $offer->id]);
});
