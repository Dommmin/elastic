<?php

use App\Models\Offer;
use App\Models\Product;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Opublikowana oferta z oczekującą zmianą ceny i jej sprzedawca.
 *
 * @return array{0: User, 1: Offer}
 */
function moderationTestPendingOffer(int $priceCents = 10000, int $proposedCents = 15000, ?string $requestedAt = null): array
{
    $user = User::factory()->create();
    $seller = Seller::factory()->forUser($user)->create(['name' => 'Sklep Moderowany']);
    $offer = Offer::factory()->for($seller)->create([
        'price_cents' => $priceCents,
        'stock' => 5,
        'pending_price_cents' => $proposedCents,
        'pending_requested_at' => $requestedAt ?? now(),
    ]);

    return [$user, $offer];
}

/**
 * @return list<string>
 */
function moderationTestProjectionOfferIds(int $productId): array
{
    return test()->getJson("/api/internal/products/{$productId}/projection")->json('offers.*.offer_id') ?? [];
}

test('kolejka pokazuje zgłoszenie z różnicą w %', function () {
    [, $newer] = moderationTestPendingOffer(10000, 15000, now()->toDateTimeString());
    [, $older] = moderationTestPendingOffer(20000, 10000, now()->subHour()->toDateTimeString());
    Offer::factory()->create(['price_cents' => 5000]);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/panel/moderation/price-changes')
        ->assertInertia(fn (Assert $page) => $page
            ->component('panel/moderation/PriceChanges')
            ->has('items', 2)
            ->where('items.0.offer_id', $older->id)
            ->where('items.0.change_percent', 50)
            ->where('items.0.current_price_cents', 20000)
            ->where('items.0.proposed_price_cents', 10000)
            ->where('items.1.offer_id', $newer->id)
            ->where('items.1.change_percent', 50)
            ->where('items.1.current_price_cents', 10000)
            ->where('items.1.proposed_price_cents', 15000)
            ->where('items.1.product.id', $newer->product_id)
            ->where('items.1.product.name', $newer->product->name)
            ->where('items.1.seller.name', 'Sklep Moderowany')
            ->has('items.1.requested_at')
            ->where('moderation.pending_price_changes', 2));
});

test('licznik zmian cen tylko dla admina', function () {
    moderationTestPendingOffer();

    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('moderation', null));

    $this->actingAs(User::factory()->admin()->create())
        ->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('moderation.pending_price_changes', 1));
});

test('akceptacja zmienia cenę i daje offer.price_change_approved', function () {
    [, $offer] = moderationTestPendingOffer(10000, 15000);

    $this->actingAs(User::factory()->admin()->create())
        ->from('/panel/moderation/price-changes')
        ->post("/panel/offers/{$offer->id}/price-change/approve")
        ->assertRedirect('/panel/moderation/price-changes')
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Nowa cena zaakceptowana');

    expect($offer->fresh())
        ->price_cents->toBe(15000)
        ->pending_price_cents->toBeNull()
        ->and(DB::table('outbox')->where('aggregate_id', (string) $offer->id)->where('event_type', 'offer.price_change_approved')->count())->toBe(1);
    $this->getJson("/api/internal/products/{$offer->product_id}/projection")->assertJsonPath('price_min', 15000);
});

test('ponowna akceptacja bez oczekującej zmiany → błąd domain', function () {
    [, $offer] = moderationTestPendingOffer();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->post("/panel/offers/{$offer->id}/price-change/approve")->assertSessionHasNoErrors();

    $this->actingAs($admin)
        ->from('/panel/moderation/price-changes')
        ->post("/panel/offers/{$offer->id}/price-change/approve")
        ->assertRedirect('/panel/moderation/price-changes')
        ->assertSessionHasErrors(['domain' => 'Ta oferta nie ma już zgłoszonej zmiany ceny']);
});

test('odrzucenie bez powodu → błąd reason', function (string $reason) {
    [, $offer] = moderationTestPendingOffer(10000, 15000);

    $this->actingAs(User::factory()->admin()->create())
        ->from('/panel/moderation/price-changes')
        ->post("/panel/offers/{$offer->id}/price-change/reject", ['reason' => $reason])
        ->assertRedirect('/panel/moderation/price-changes')
        ->assertSessionHasErrors('reason');

    expect($offer->fresh()->pending_price_cents)->toBe(15000);
})->with(['pusty' => [''], 'same spacje' => ['   ']]);

test('odrzucenie z powodem zostawia starą cenę', function () {
    [, $offer] = moderationTestPendingOffer(10000, 15000);

    $this->actingAs(User::factory()->admin()->create())
        ->from('/panel/moderation/price-changes')
        ->post("/panel/offers/{$offer->id}/price-change/reject", ['reason' => 'Cena odbiega od rynku'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Zmiana ceny odrzucona');

    expect($offer->fresh())
        ->price_cents->toBe(10000)
        ->pending_price_cents->toBeNull()
        ->and(DB::table('outbox')->where('aggregate_id', (string) $offer->id)->where('event_type', 'offer.price_change_rejected')->count())->toBe(1);
});

test('zawieszenie znika ofertę z projekcji, przywrócenie wraca', function () {
    $product = Product::factory()->create();
    $offer = Offer::factory()->for($product)->create(['stock' => 5]);
    $admin = User::factory()->admin()->create();
    $edit = "/panel/products/{$product->id}";

    expect(moderationTestProjectionOfferIds($product->id))->toBe([(string) $offer->id]);

    $this->actingAs($admin)
        ->get($edit)
        ->assertInertia(fn (Assert $page) => $page
            ->where('offers.0.id', $offer->id)
            ->where('offers.0.status', 'published')
            ->where('offers.0.suspension', null)
            ->where('offers.0.allowed_actions', ['suspend']));

    $this->actingAs($admin)->from($edit)->post("/panel/offers/{$offer->id}/suspend", ['note' => ''])
        ->assertSessionHasErrors('note');
    expect($offer->fresh()->status)->toBe('published');

    $this->actingAs($admin)
        ->from($edit)
        ->post("/panel/offers/{$offer->id}/suspend", ['note' => 'Podróbka'])
        ->assertRedirect($edit)
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Oferta zawieszona');

    expect($offer->fresh())
        ->status->toBe('suspended')
        ->suspension_kind->toBe('moderation')
        ->suspended_by->toBe($admin->id)
        ->and(moderationTestProjectionOfferIds($product->id))->toBe([]);

    $this->actingAs($admin)
        ->get($edit)
        ->assertInertia(fn (Assert $page) => $page
            ->where('offers.0.status', 'suspended')
            ->where('offers.0.suspension.kind', 'moderation')
            ->where('offers.0.suspension.note', 'Podróbka')
            ->where('offers.0.allowed_actions', ['reinstate']));

    $this->actingAs($admin)
        ->from($edit)
        ->post("/panel/offers/{$offer->id}/reinstate")
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Oferta przywrócona');

    expect($offer->fresh()->status)->toBe('published')
        ->and(moderationTestProjectionOfferIds($product->id))->toBe([(string) $offer->id]);
});

test('wiersz oferty w panelu: sprzedawca, jego status i akcje moderatora przy oczekującej cenie', function () {
    [, $offer] = moderationTestPendingOffer();

    $this->actingAs(User::factory()->admin()->create())
        ->get("/panel/products/{$offer->product_id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('offers.0.seller', 'Sklep Moderowany')
            ->where('offers.0.seller_status', 'active')
            ->where('offers.0.pending_price_cents', 15000)
            ->where('offers.0.allowed_actions', ['suspend', 'approve_price_change', 'reject_price_change']));
});

test('sprzedawca nie może przywrócić zawieszonej (brak trasy/akcji)', function () {
    $user = User::factory()->create();
    $seller = Seller::factory()->forUser($user)->create();
    $offer = Offer::factory()->for($seller)->create(['status' => 'suspended', 'suspension_kind' => 'moderation', 'suspension_note' => 'Podróbka', 'suspended_by' => User::factory()->admin()->create()->id]);

    $this->actingAs($user)
        ->get("/seller/offers/{$offer->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('allowed_actions', fn ($actions) => ! collect($actions)->contains('reinstate')));

    $this->actingAs($user)->post("/panel/offers/{$offer->id}/reinstate")->assertForbidden();
    $this->actingAs($user)->post("/panel/offers/{$offer->id}/price-change/approve")->assertForbidden();
    $this->actingAs($user)->get('/panel/moderation/price-changes')->assertForbidden();
    $this->actingAs($user)->post("/seller/offers/{$offer->id}/reinstate")->assertNotFound();

    expect($offer->fresh()->status)->toBe('suspended');
});

test('akcja moderatora na nieistniejącej ofercie → 404', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/panel/offers/999999/suspend', ['note' => 'X'])->assertNotFound();
    $this->actingAs($admin)->post('/panel/offers/999999/reinstate')->assertNotFound();
    $this->actingAs($admin)->post('/panel/offers/999999/price-change/approve')->assertNotFound();
    $this->actingAs($admin)->post('/panel/offers/999999/price-change/reject', ['reason' => 'X'])->assertNotFound();
});

test('przywrócenie opublikowanej → błąd domain', function () {
    $offer = Offer::factory()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->from('/panel')
        ->post("/panel/offers/{$offer->id}/reinstate")
        ->assertSessionHasErrors(['domain' => 'Operacja niedozwolona w obecnym stanie']);
});
