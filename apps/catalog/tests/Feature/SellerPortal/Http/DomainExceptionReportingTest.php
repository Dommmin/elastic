<?php

use App\Models\User;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use SellerPortal\Domain\Offer\PriceChangeLimitExceeded;
use SellerPortal\Domain\Shared\ConcurrencyConflict;
use SellerPortal\Domain\Shared\InvalidValue;

test('złamana reguła domeny wraca jako błąd domain i nie trafia do logu', function (Throwable $exception) {
    Exceptions::fake();
    Route::middleware('web')->post('/__domain-reporting-test', fn () => throw $exception);

    $this->actingAs(User::factory()->create())
        ->from('/dashboard')
        ->post('/__domain-reporting-test')
        ->assertRedirect('/dashboard')
        ->assertSessionHasErrors('domain');

    Exceptions::assertNotReported($exception::class);
})->with([
    'limit zmian ceny' => fn () => new PriceChangeLimitExceeded,
    'konflikt wersji' => fn () => new ConcurrencyConflict,
]);

test('InvalidValue jest zgłaszany — wartość spoza zakresu za walidacją formularza to błąd w kodzie', function () {
    Exceptions::fake();
    Route::middleware('web')->post('/__domain-reporting-test', fn () => throw new InvalidValue('Kwota musi być większa od zera.'));

    $this->actingAs(User::factory()->create())
        ->from('/dashboard')
        ->post('/__domain-reporting-test')
        ->assertSessionHasErrors(['domain' => 'Operacja niedozwolona w obecnym stanie']);

    Exceptions::assertReported(InvalidValue::class);
});
