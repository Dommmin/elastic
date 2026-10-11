<?php

use App\Models\User;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('rejestracja nie nadaje uprawnień administratora nawet z is_admin w żądaniu', function () {
    $this->post(route('register.store'), [
        'name' => 'Intruz',
        'email' => 'intruz@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'is_admin' => '1',
    ]);

    $user = User::where('email', 'intruz@example.com')->firstOrFail();
    expect($user->is_admin)->toBeFalse();

    $this->get('/panel/products')->assertForbidden();
});
