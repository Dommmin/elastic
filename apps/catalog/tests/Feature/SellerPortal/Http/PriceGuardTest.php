<?php

use App\Models\Offer;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{0: User, 1: Offer}
 */
function priceGuardTestOffer(int $priceCents = 10000): array
{
    $user = User::factory()->create();
    $seller = Seller::factory()->forUser($user)->create();
    $offer = Offer::factory()->for($seller)->create(['price_cents' => $priceCents, 'stock' => 5]);

    return [$user, $offer];
}

test('+10% → „Cena zmieniona”, cena od razu w projekcji', function () {
    [$user, $offer] = priceGuardTestOffer(10000);

    $this->actingAs($user)
        ->from("/seller/offers/{$offer->id}")
        ->put("/seller/offers/{$offer->id}/price", ['price' => '110,00'])
        ->assertRedirect("/seller/offers/{$offer->id}")
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Cena zmieniona');

    expect($offer->fresh()->price_cents)->toBe(11000);
    $this->getJson("/api/internal/products/{$offer->product_id}/projection")->assertJsonPath('price_min', 11000);
});

test('+50% → flash o akceptacji, cena w projekcji bez zmian', function () {
    [$user, $offer] = priceGuardTestOffer(10000);

    $this->actingAs($user)
        ->from("/seller/offers/{$offer->id}")
        ->put("/seller/offers/{$offer->id}/price", ['price' => '150'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Zmiana o ponad 30% czeka na akceptację moderatora');

    expect($offer->fresh())
        ->price_cents->toBe(10000)
        ->pending_price_cents->toBe(15000);
    $this->getJson("/api/internal/products/{$offer->product_id}/projection")->assertJsonPath('price_min', 10000);

    $this->actingAs($user)
        ->get("/seller/offers/{$offer->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('offer.price_cents', 10000)
            ->where('offer.pending_price.price_cents', 15000)
            ->has('offer.pending_price.requested_at')
            ->where('price_guard.changes_left_today', 4));
});

test('szósta zmiana w dobie → błąd „Wykorzystano limit 5 zmian ceny na dobę”', function () {
    Carbon::setTestNow('2026-10-15 10:00:00');
    [$user, $offer] = priceGuardTestOffer(10000);
    $show = "/seller/offers/{$offer->id}";

    foreach ([101, 102, 103, 104, 105] as $i => $zloty) {
        Carbon::setTestNow(Carbon::parse('2026-10-15 10:00:00')->addMinutes($i));
        $this->actingAs($user)->from($show)->put("{$show}/price", ['price' => (string) $zloty])->assertSessionHasNoErrors();
    }

    $this->actingAs($user)
        ->get($show)
        ->assertInertia(fn (Assert $page) => $page->where('price_guard.changes_left_today', 0));

    $this->actingAs($user)
        ->from($show)
        ->put("{$show}/price", ['price' => '106'])
        ->assertRedirect($show)
        ->assertSessionHasErrors(['domain' => 'Wykorzystano limit 5 zmian ceny na dobę']);
    expect($offer->fresh()->price_cents)->toBe(10500);

    // Okno kroczące: po 24 h od pierwszej zmiany zwalnia się jedno miejsce.
    Carbon::setTestNow('2026-10-16 10:00:01');
    $this->actingAs($user)->from($show)->put("{$show}/price", ['price' => '106'])->assertSessionHasNoErrors();
    expect($offer->fresh()->price_cents)->toBe(10600);

    Carbon::setTestNow();
});

test('równoległa edycja → błąd concurrency', function () {
    [$user, $offer] = priceGuardTestOffer();
    $outboxBefore = DB::table('outbox')->count();

    // Ktoś inny zapisuje ofertę między odczytem a zapisem tego żądania.
    // DLACZEGO na poziomie SQL, a nie przez podmianę repozytorium: test ma
    // sprawdzać zachowanie HTTP niezależnie od tego, jak kod zapisuje ofertę
    // (ten sam plik przechodzi na gałęzi `ddd-lite-porownanie`, gdzie
    // repozytorium nie ma). Tuż przed pierwszym UPDATE-em `offers` wersja
    // w bazie idzie o 1 w górę; flaga chroni przed rekurencją, bo sam
    // `increment` też jest UPDATE-em `offers`.
    $raced = false;
    DB::beforeExecuting(function (string $query) use (&$raced, $offer): void {
        if ($raced || ! str_starts_with(strtolower($query), 'update "offers"')) {
            return;
        }

        $raced = true;
        DB::table('offers')->where('id', $offer->id)->increment('version');
    });

    $this->actingAs($user)
        ->from("/seller/offers/{$offer->id}")
        ->put("/seller/offers/{$offer->id}/stock", ['stock' => 42])
        ->assertRedirect("/seller/offers/{$offer->id}")
        ->assertSessionHasErrors(['domain' => 'Ktoś w międzyczasie zmienił tę ofertę — odśwież stronę']);

    expect($offer->fresh()->stock)->toBe(5)
        ->and(DB::table('outbox')->count())->toBe($outboxBefore);
});
