<?php

use App\Models\Offer;
use App\Models\Product;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{0: User, 1: Seller}
 */
function sellerOfferTestAccount(string $status = 'active'): array
{
    $user = User::factory()->create();
    $seller = Seller::factory()->forUser($user)->create(['status' => $status]);

    return [$user, $seller];
}

/**
 * @return array<string, mixed>
 */
function sellerOfferTestDraftPayload(Product $product, array $overrides = []): array
{
    return [
        'product_id' => $product->id,
        'price' => '49,99',
        'stock' => 5,
        'condition' => 'new',
        'shipping_days' => 2,
        ...$overrides,
    ];
}

test('sprzedawca tworzy szkic, publikuje, oferta jest listed', function () {
    [$user, $seller] = sellerOfferTestAccount();
    $product = Product::factory()->create();

    $this->actingAs($user)
        ->get('/seller/offers/create')
        ->assertInertia(fn (Assert $page) => $page->component('seller/offers/Create'));

    $response = $this->actingAs($user)->post('/seller/offers', sellerOfferTestDraftPayload($product));

    $offer = Offer::where('seller_id', $seller->id)->sole();
    $response->assertRedirect(route('seller.offers.show', $offer->id))
        ->assertInertiaFlash('toast.message', 'Szkic oferty zapisany');

    expect($offer)
        ->status->toBe('draft')
        ->price_cents->toBe(4999)
        ->stock->toBe(5)
        ->condition->toBe('new')
        ->shipping_days->toBe(2);

    $this->actingAs($user)
        ->get("/seller/offers/{$offer->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->component('seller/offers/Show')
            ->where('offer.id', $offer->id)
            ->where('offer.product.id', $product->id)
            ->where('offer.product.name', $product->name)
            ->where('offer.status', 'draft')
            ->where('offer.is_listed', false)
            ->where('allowed_actions', ['publish', 'change_price', 'change_stock', 'change_details', 'withdraw'])
            ->where('price_guard.threshold_percent', 30)
            ->where('price_guard.changes_left_today', 5)
            ->where('timeline.0.type', 'offer.drafted')
            ->where('timeline.0.published', false));

    $this->actingAs($user)
        ->from("/seller/offers/{$offer->id}")
        ->post("/seller/offers/{$offer->id}/publish")
        ->assertRedirect("/seller/offers/{$offer->id}")
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Oferta opublikowana');

    expect($offer->fresh()->status)->toBe('published');

    $this->actingAs($user)
        ->get("/seller/offers/{$offer->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('offer.status', 'published')
            ->where('offer.is_listed', true)
            ->where('offer.is_sold_out', false)
            ->where('offer.version', 2)
            ->where('timeline.0.type', 'offer.published')
            ->has('timeline', 2));
});

test('cena „1 299,99” zapisuje 129999 groszy', function (string $input, int $cents) {
    [$user, $seller] = sellerOfferTestAccount();
    $product = Product::factory()->create();

    $this->actingAs($user)
        ->post('/seller/offers', sellerOfferTestDraftPayload($product, ['price' => $input]))
        ->assertSessionHasNoErrors();

    expect(Offer::where('seller_id', $seller->id)->sole()->price_cents)->toBe($cents);
})->with([
    'spacja i przecinek' => ['1 299,99', 129999],
    'twarda spacja z Intl' => ["1\u{00A0}299,99", 129999],
    'kropka' => ['1299.99', 129999],
    'liczba całkowita' => ['1299', 129900],
    'jedno miejsce' => ['0,5', 50],
]);

test('niepoprawna cena → błąd price, brak oferty', function (string $input) {
    [$user] = sellerOfferTestAccount();
    $product = Product::factory()->create();

    $this->actingAs($user)
        ->from('/seller/offers/create')
        ->post('/seller/offers', sellerOfferTestDraftPayload($product, ['price' => $input]))
        ->assertRedirect('/seller/offers/create')
        ->assertSessionHasErrors('price');

    expect(Offer::count())->toBe(0);
})->with([
    'trzy miejsca' => ['12,999'],
    'zero' => ['0'],
    'zero z groszami' => ['0,00'],
    'ujemna' => ['-5'],
    'tekst' => ['abc'],
    'pusta' => [''],
]);

test('oferta na produkt z żywą ofertą → błąd domain', function () {
    [$user, $seller] = sellerOfferTestAccount();
    $product = Product::factory()->create();
    Offer::factory()->for($product)->for($seller)->create();

    $this->actingAs($user)
        ->from('/seller/offers/create')
        ->post('/seller/offers', sellerOfferTestDraftPayload($product))
        ->assertRedirect('/seller/offers/create')
        ->assertSessionHasErrors(['domain' => 'Masz już aktywną ofertę na ten produkt']);

    expect(Offer::where('seller_id', $seller->id)->count())->toBe(1);
});

test('nieistniejący produkt → błąd product_id', function () {
    [$user] = sellerOfferTestAccount();

    $this->actingAs($user)
        ->post('/seller/offers', ['product_id' => 999999, 'price' => '10', 'stock' => 1, 'condition' => 'new', 'shipping_days' => 1])
        ->assertSessionHasErrors('product_id');

    expect(Offer::count())->toBe(0);
});

test('cudza oferta → 404 i brak zmian', function () {
    [$user] = sellerOfferTestAccount();
    $foreign = Offer::factory()->create(['price_cents' => 10000, 'stock' => 3, 'condition' => 'new', 'shipping_days' => 2]);
    $before = DB::table('offers')->where('id', $foreign->id)->first();
    $outboxBefore = DB::table('outbox')->count();

    $this->actingAs($user)->get("/seller/offers/{$foreign->id}")->assertNotFound();
    $this->actingAs($user)->post("/seller/offers/{$foreign->id}/publish")->assertNotFound();
    $this->actingAs($user)->put("/seller/offers/{$foreign->id}/price", ['price' => '105'])->assertNotFound();
    $this->actingAs($user)->delete("/seller/offers/{$foreign->id}/pending-price")->assertNotFound();
    $this->actingAs($user)->put("/seller/offers/{$foreign->id}/stock", ['stock' => 9])->assertNotFound();
    $this->actingAs($user)->put("/seller/offers/{$foreign->id}/details", ['condition' => 'used', 'shipping_days' => 7])->assertNotFound();
    $this->actingAs($user)->post("/seller/offers/{$foreign->id}/withdraw")->assertNotFound();
    $this->actingAs($user)->get('/seller/offers/999999')->assertNotFound();

    expect(DB::table('offers')->where('id', $foreign->id)->first())->toEqual($before)
        ->and(DB::table('outbox')->count())->toBe($outboxBefore);
});

test('pending/rejected sprzedawca jest przekierowany na status', function (string $status) {
    [$user, $seller] = sellerOfferTestAccount($status);
    $offer = Offer::factory()->for($seller)->create();

    foreach (['/seller', '/seller/offers', '/seller/offers/create', "/seller/offers/{$offer->id}", '/seller/products/lookup?q=ab'] as $url) {
        $this->actingAs($user)->get($url)->assertRedirect(route('seller.status'));
    }

    $this->actingAs($user)->post("/seller/offers/{$offer->id}/withdraw")->assertRedirect(route('seller.status'));
    expect($offer->fresh()->status)->toBe('published');
})->with(['pending', 'rejected']);

test('użytkownik bez sprzedawcy trafia na zgłoszenie, gość na logowanie', function () {
    $this->actingAs(User::factory()->create())->get('/seller/offers')->assertRedirect(route('seller.apply'));

    auth()->logout();
    $this->get('/seller/offers')->assertRedirect(route('login'));
});

test('zablokowany widzi oferty i baner, zmiana ceny → błąd domain, wycofanie działa', function () {
    [$user, $seller] = sellerOfferTestAccount('blocked');
    $offer = Offer::factory()->for($seller)->create(['price_cents' => 10000, 'status' => 'suspended', 'suspension_kind' => 'seller_blocked']);

    $this->actingAs($user)
        ->get('/seller/offers')
        ->assertInertia(fn (Assert $page) => $page
            ->component('seller/offers/Index')
            ->where('auth.seller.status', 'blocked')
            ->has('offers.data', 1)
            ->where('offers.data.0.id', $offer->id)
            ->where('offers.data.0.status', 'suspended'));

    // Zablokowanemu UI proponuje tylko wycofanie — pozostałe akcje odrzuci warstwa aplikacji.
    $this->actingAs($user)
        ->get("/seller/offers/{$offer->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('offer.suspension.kind', 'seller_blocked')
            ->where('allowed_actions', ['withdraw']));

    $this->actingAs($user)
        ->from("/seller/offers/{$offer->id}")
        ->put("/seller/offers/{$offer->id}/price", ['price' => '101'])
        ->assertRedirect("/seller/offers/{$offer->id}")
        ->assertSessionHasErrors(['domain' => 'Twoje konto sprzedawcy nie jest aktywne']);
    expect($offer->fresh()->price_cents)->toBe(10000);

    $this->actingAs($user)
        ->from("/seller/offers/{$offer->id}")
        ->post("/seller/offers/{$offer->id}/withdraw")
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Oferta wycofana');
    expect($offer->fresh()->status)->toBe('withdrawn');
});

test('zablokowany nie tworzy szkicu', function () {
    [$user] = sellerOfferTestAccount('blocked');
    $product = Product::factory()->create();

    $this->actingAs($user)
        ->from('/seller/offers/create')
        ->post('/seller/offers', sellerOfferTestDraftPayload($product))
        ->assertSessionHasErrors(['domain' => 'Twoje konto sprzedawcy nie jest aktywne']);

    expect(Offer::count())->toBe(0);
});

test('allowed_actions dla opublikowanej bez pending', function () {
    [$user, $seller] = sellerOfferTestAccount();
    $offer = Offer::factory()->for($seller)->create();

    $this->actingAs($user)
        ->get("/seller/offers/{$offer->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('allowed_actions', ['change_price', 'change_stock', 'change_details', 'withdraw'])
            ->where('offer.pending_price', null)
            ->where('offer.suspension', null));
});

test('stan 0 → wyprzedana, szczegóły i anulowanie oczekującej ceny', function () {
    [$user, $seller] = sellerOfferTestAccount();
    $offer = Offer::factory()->for($seller)->create(['price_cents' => 10000, 'stock' => 3, 'condition' => 'new', 'shipping_days' => 2]);
    $show = "/seller/offers/{$offer->id}";

    $this->actingAs($user)->from($show)->put("{$show}/stock", ['stock' => 0])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Stan magazynowy zapisany');
    $this->actingAs($user)->from($show)->put("{$show}/details", ['condition' => 'used', 'shipping_days' => 7])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Szczegóły oferty zapisane');

    expect($offer->fresh())
        ->stock->toBe(0)
        ->condition->toBe('used')
        ->shipping_days->toBe(7);

    $this->actingAs($user)->from($show)->put("{$show}/price", ['price' => '200'])->assertSessionHasNoErrors();

    $this->actingAs($user)
        ->get($show)
        ->assertInertia(fn (Assert $page) => $page
            ->where('offer.is_sold_out', true)
            ->where('offer.is_listed', false)
            ->where('offer.pending_price.price_cents', 20000)
            ->where('allowed_actions', ['cancel_pending_price_change', 'change_stock', 'change_details', 'withdraw']));

    $this->actingAs($user)->from($show)->delete("{$show}/pending-price")
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Zgłoszenie zmiany ceny anulowane');

    expect($offer->fresh()->pending_price_cents)->toBeNull();
});

test('walidacja stanu i szczegółów', function () {
    [$user, $seller] = sellerOfferTestAccount();
    $offer = Offer::factory()->for($seller)->create(['stock' => 3]);

    $this->actingAs($user)->put("/seller/offers/{$offer->id}/stock", ['stock' => -1])->assertSessionHasErrors('stock');
    $this->actingAs($user)->put("/seller/offers/{$offer->id}/stock", ['stock' => 100001])->assertSessionHasErrors('stock');
    $this->actingAs($user)->put("/seller/offers/{$offer->id}/details", ['condition' => 'broken', 'shipping_days' => 31])
        ->assertSessionHasErrors(['condition', 'shipping_days']);

    expect($offer->fresh()->stock)->toBe(3);
});

test('lista: filtr statusu, w tym wyprzedane i oczekujące zmiany ceny', function () {
    [$user, $seller] = sellerOfferTestAccount();
    $draft = Offer::factory()->for($seller)->draft()->create();
    $published = Offer::factory()->for($seller)->create(['stock' => 4]);
    $soldOut = Offer::factory()->for($seller)->soldOut()->create();
    $pending = Offer::factory()->for($seller)->create(['price_cents' => 1000, 'pending_price_cents' => 5000, 'pending_requested_at' => now()]);
    $withdrawn = Offer::factory()->for($seller)->withdrawn()->create();
    Offer::factory()->create();

    $ids = fn (string $query) => collect($this->actingAs($user)->get("/seller/offers{$query}")->viewData('page')['props']['offers']['data'])
        ->pluck('id')->sort()->values()->all();

    expect($ids(''))->toBe(collect([$draft, $published, $soldOut, $pending, $withdrawn])->pluck('id')->sort()->values()->all())
        ->and($ids('?status=draft'))->toBe([$draft->id])
        ->and($ids('?status=published'))->toBe(collect([$published, $soldOut, $pending])->pluck('id')->sort()->values()->all())
        ->and($ids('?status=sold_out'))->toBe([$soldOut->id])
        ->and($ids('?status=pending_price'))->toBe([$pending->id])
        ->and($ids('?status=withdrawn'))->toBe([$withdrawn->id])
        ->and($ids('?status=nonsense'))->toHaveCount(5);

    $this->actingAs($user)
        ->get('/seller/offers?status=sold_out')
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.status', 'sold_out')
            ->where('offers.data.0.is_sold_out', true)
            ->has('offers.data.0.product.name'));
});

test('pulpit liczy oferty według statusu, wyprzedane i oczekujące ceny', function () {
    [$user, $seller] = sellerOfferTestAccount();
    Offer::factory()->for($seller)->draft()->create();
    Offer::factory()->for($seller)->count(2)->create(['stock' => 4]);
    Offer::factory()->for($seller)->soldOut()->create();
    Offer::factory()->for($seller)->create(['price_cents' => 1000, 'pending_price_cents' => 5000, 'pending_requested_at' => now()]);
    Offer::factory()->for($seller)->withdrawn()->create();
    Offer::factory()->count(3)->create();

    $this->actingAs($user)
        ->get('/seller')
        ->assertInertia(fn (Assert $page) => $page
            ->component('seller/Dashboard')
            ->where('stats', [
                'draft' => 1,
                'published' => 4,
                'suspended' => 0,
                'withdrawn' => 1,
                'sold_out' => 1,
                'pending_price' => 1,
            ]));
});

test('lookup po EAN i po nazwie, max 10, bez produktów z własną żywą ofertą', function () {
    [$user, $seller] = sellerOfferTestAccount();
    $byEan = Product::factory()->create(['name' => 'Czajnik', 'ean' => '5901234123457']);
    Product::factory()->count(12)->sequence(fn ($sequence) => ['name' => "Laptop {$sequence->index}"])->create();
    $mine = Product::factory()->create(['name' => 'Laptop własny']);
    Offer::factory()->for($mine)->for($seller)->create();
    $mineWithdrawn = Product::factory()->create(['name' => 'Laptop wycofany']);
    Offer::factory()->for($mineWithdrawn)->for($seller)->withdrawn()->create();

    $this->actingAs($user)
        ->getJson('/seller/products/lookup?q=5901234123457')
        ->assertOk()
        ->assertExactJson([['id' => $byEan->id, 'name' => 'Czajnik', 'brand' => $byEan->brand->name, 'ean' => '5901234123457']]);

    $this->actingAs($user)
        ->getJson('/seller/products/lookup?q=59012341')
        ->assertOk()
        ->assertJsonPath('0.id', $byEan->id);

    $names = $this->actingAs($user)
        ->getJson('/seller/products/lookup?q=laptop')
        ->assertOk()
        ->assertJsonCount(10)
        ->json('*.name');
    expect($names)->not->toContain('Laptop własny');

    $this->actingAs($user)
        ->getJson('/seller/products/lookup?q=wycof')
        ->assertJsonPath('0.id', $mineWithdrawn->id);

    $this->actingAs($user)
        ->getJson('/seller/products/lookup?q=własny')
        ->assertExactJson([]);

    $this->actingAs($user)
        ->getJson('/seller/products/lookup?q=%25_')
        ->assertExactJson([]);

    $this->actingAs($user)
        ->getJson('/seller/products/lookup?q=l')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('q');
});
