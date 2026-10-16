<?php

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use SellerPortal\Domain\Offer\PriceChangeLimitExceeded;
use SellerPortal\Domain\Seller\SellerNotFound;
use SharedKernel\ConcurrencyConflict;
use SharedKernel\InvalidValue;

/**
 * Testy biegną z CLI, więc `runningInConsole()` jest tu zawsze `true`.
 * Żądanie HTTP z produkcji (Octane: `APP_RUNNING_IN_CONSOLE=false`)
 * udajemy, ustawiając tę samą flagę aplikacji, którą Laravel czyta leniwie.
 * Ochrona CSRF (`PreventRequestForgery`) pomija testy tylko „w konsoli”, więc wyłączamy ją jawnie.
 */
function domainReportingAsHttpServer(): void
{
    (fn () => $this->isRunningInConsole = false)->call(app());
    test()->withoutMiddleware(PreventRequestForgery::class);
}

test('złamana reguła domeny wraca jako błąd domain i nie trafia do logu', function (Throwable $exception) {
    domainReportingAsHttpServer();
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
    domainReportingAsHttpServer();
    Exceptions::fake();
    Route::middleware('web')->post('/__domain-reporting-test', fn () => throw new InvalidValue('Kwota musi być większa od zera.'));

    $this->actingAs(User::factory()->create())
        ->from('/dashboard')
        ->post('/__domain-reporting-test')
        ->assertSessionHasErrors(['domain' => 'Operacja niedozwolona w obecnym stanie']);

    Exceptions::assertReported(InvalidValue::class);
});

test('w konsoli i workerze kolejki błąd domeny trafia do logu (nieudany job polityki zostawia ślad)', function (Throwable $exception) {
    Exceptions::fake();

    expect(app()->runningInConsole())->toBeTrue();

    // Tak samo zgłasza wyjątek `queue:work`, gdy job pada.
    report($exception);

    Exceptions::assertReported($exception::class);
})->with([
    'konflikt wersji po 3 próbach' => fn () => new ConcurrencyConflict,
    'brak sprzedawcy' => fn () => new SellerNotFound,
]);
