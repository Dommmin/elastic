<?php

use App\Models\Seller;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('lista moderacji domyślnie ukrywa syntetycznych sprzedawców', function () {
    $real = Seller::factory()->create();
    Seller::factory()->create(['synthetic' => true]);
    Seller::factory()->pending()->forUser(User::factory()->create())->create(['synthetic' => true]);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/panel/sellers')
        ->assertInertia(fn (Assert $page) => $page
            ->component('panel/sellers/Index')
            ->has('pending', 0)
            ->has('sellers.data', 1)
            ->where('sellers.data.0.id', $real->id)
            ->where('filters.synthetic', false));
});

test('?synthetic=1 pokazuje wszystkich sprzedawców', function () {
    Seller::factory()->create();
    Seller::factory()->create(['synthetic' => true]);
    Seller::factory()->pending()->forUser(User::factory()->create())->create(['synthetic' => true]);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/panel/sellers?synthetic=1')
        ->assertInertia(fn (Assert $page) => $page
            ->has('pending', 1)
            ->has('sellers.data', 3)
            ->where('filters.synthetic', true));
});
