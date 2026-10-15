<?php

use App\Models\Seller;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

test('zalogowany bez sprzedawcy składa zgłoszenie i widzi status oczekuje', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/seller/apply')
        ->assertInertia(fn (Assert $page) => $page->component('seller/Apply'));

    $this->actingAs($user)
        ->post('/seller/apply', ['name' => 'Sklep Testowy', 'city' => 'Kraków'])
        ->assertRedirect(route('seller.status'));

    $row = DB::table('sellers')->where('user_id', $user->id)->first();
    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('pending')
        ->and($row->name)->toBe('Sklep Testowy');

    $this->actingAs($user)
        ->get('/seller/status')
        ->assertInertia(fn (Assert $page) => $page
            ->component('seller/Status')
            ->where('seller.status', 'pending')
            ->where('seller.name', 'Sklep Testowy'));
});

test('nazwa krótsza niż 3 znaki → błąd walidacji name', function () {
    $this->actingAs(User::factory()->create())
        ->from('/seller/apply')
        ->post('/seller/apply', ['name' => 'ab', 'city' => 'Kraków'])
        ->assertRedirect('/seller/apply')
        ->assertSessionHasErrors('name');

    expect(DB::table('sellers')->count())->toBe(0);
});

test('drugie zgłoszenie → przekierowanie na status, bez nowego wiersza', function () {
    $user = User::factory()->create();
    Seller::factory()->pending()->forUser($user)->create();

    $this->actingAs($user)
        ->get('/seller/apply')
        ->assertRedirect(route('seller.status'));

    $this->actingAs($user)
        ->post('/seller/apply', ['name' => 'Drugi Sklep', 'city' => 'Gdańsk'])
        ->assertRedirect(route('seller.status'));

    expect(DB::table('sellers')->where('user_id', $user->id)->count())->toBe(1);
});

test('status bez sprzedawcy przekierowuje na zgłoszenie', function () {
    $this->actingAs(User::factory()->create())
        ->get('/seller/status')
        ->assertRedirect(route('seller.apply'));
});

test('admin widzi oczekujących i akceptuje', function () {
    $older = Seller::factory()->pending()->forUser(User::factory()->create())->create(['created_at' => now()->subDay()]);
    $newer = Seller::factory()->pending()->forUser(User::factory()->create())->create();
    Seller::factory()->create(['name' => 'Stary Sklep']);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/panel/sellers')
        ->assertInertia(fn (Assert $page) => $page
            ->component('panel/sellers/Index')
            ->has('pending', 2)
            ->where('pending.0.id', $older->id)
            ->where('pending.1.id', $newer->id)
            ->has('sellers.data', 3)
            ->where('filters.status', null));

    $this->actingAs($admin)
        ->from('/panel/sellers')
        ->post("/panel/sellers/{$older->id}/approve")
        ->assertRedirect('/panel/sellers')
        ->assertSessionHasNoErrors();

    expect($older->fresh()->status)->toBe('active');
});

test('filtr status i sprzedawca bez konta na liście', function () {
    Seller::factory()->pending()->forUser(User::factory()->create())->create();
    $legacy = Seller::factory()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get('/panel/sellers?status=active')
        ->assertInertia(fn (Assert $page) => $page
            ->has('sellers.data', 1)
            ->where('sellers.data.0.id', $legacy->id)
            ->where('sellers.data.0.has_account', false)
            ->where('filters.status', 'active'));
});

test('odrzucenie bez powodu → błąd reason', function () {
    $seller = Seller::factory()->pending()->forUser(User::factory()->create())->create();

    $this->actingAs(User::factory()->admin()->create())
        ->from('/panel/sellers')
        ->post("/panel/sellers/{$seller->id}/reject", ['reason' => ''])
        ->assertSessionHasErrors('reason');

    expect($seller->fresh()->status)->toBe('pending');
});

test('odrzucenie z powodem zapisuje powód', function () {
    $seller = Seller::factory()->pending()->forUser(User::factory()->create())->create();

    $this->actingAs(User::factory()->admin()->create())
        ->from('/panel/sellers')
        ->post("/panel/sellers/{$seller->id}/reject", ['reason' => 'Brak danych firmy'])
        ->assertSessionHasNoErrors();

    expect($seller->fresh())
        ->status->toBe('rejected')
        ->status_reason->toBe('Brak danych firmy');
});

test('blokada i odblokowanie aktywnego sprzedawcy', function () {
    $seller = Seller::factory()->forUser(User::factory()->create())->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->from('/panel/sellers')
        ->post("/panel/sellers/{$seller->id}/block", ['reason' => 'Skargi klientów'])
        ->assertSessionHasNoErrors();
    expect($seller->fresh()->status)->toBe('blocked');

    $this->actingAs($admin)
        ->from('/panel/sellers')
        ->post("/panel/sellers/{$seller->id}/unblock")
        ->assertSessionHasNoErrors();
    expect($seller->fresh()->status)->toBe('active');
});

test('nie-admin dostaje 403 na panel/sellers', function () {
    $seller = Seller::factory()->pending()->create();
    $user = User::factory()->create();

    $this->actingAs($user)->get('/panel/sellers')->assertForbidden();
    $this->actingAs($user)->post("/panel/sellers/{$seller->id}/approve")->assertForbidden();

    expect($seller->fresh()->status)->toBe('pending');
});

test('akceptacja odrzuconego → błąd domain „Operacja niedozwolona…”', function () {
    $seller = Seller::factory()->forUser(User::factory()->create())->create(['status' => 'rejected', 'status_reason' => 'Nie']);

    $this->actingAs(User::factory()->admin()->create())
        ->from('/panel/sellers')
        ->post("/panel/sellers/{$seller->id}/approve")
        ->assertRedirect('/panel/sellers')
        ->assertSessionHasErrors(['domain' => 'Operacja niedozwolona w obecnym stanie']);

    expect($seller->fresh()->status)->toBe('rejected');
});

test('akcja na nieistniejącym sprzedawcy → 404', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->post('/panel/sellers/999999/approve')
        ->assertNotFound();
});

test('shared auth.seller zawiera status', function () {
    $user = User::factory()->create();
    $seller = Seller::factory()->pending()->forUser($user)->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.seller.id', $seller->id)
            ->where('auth.seller.status', 'pending')
            ->where('auth.seller.status_reason', null));

    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('auth.seller', null));
});

test('seller.approved wpuszcza aktywnego i zablokowanego, oczekującego odsyła na status', function (string $status, bool $passes) {
    $user = User::factory()->create();
    Seller::factory()->forUser($user)->create(['status' => $status]);

    Route::middleware(['web', 'auth', 'seller', 'seller.approved'])
        ->get('/__onboarding-test-approved', fn () => 'ok');

    $response = $this->actingAs($user)->get('/__onboarding-test-approved');

    $passes ? $response->assertOk() : $response->assertRedirect(route('seller.status'));
})->with([
    'active' => ['active', true],
    'blocked' => ['blocked', true],
    'pending' => ['pending', false],
    'rejected' => ['rejected', false],
]);
