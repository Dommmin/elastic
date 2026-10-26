<?php

use App\Console\Commands\ErrorsSmokeCommand;
use App\Support\ErrorTracking\ErrorsSmokeJob;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Trasy i komenda smoke (`make errors-smoke`) wywołują błędy NA ŻYCZENIE, więc
 * istnieją tylko w APP_ENV=local. Testy pilnują obu stron tej granicy.
 */
test('trasy debug nie są zarejestrowane poza środowiskiem local', function () {
    expect(app()->environment())->toBe('testing');

    $this->get('/_debug/errors/http')->assertNotFound();
    $this->get('/_debug/errors/frontend')->assertNotFound();
});

test('trasa debug HTTP rzuca wyjątek ze znacznikiem', function () {
    Route::middleware('web')->group(base_path('routes/debug.php'));
    $this->withoutExceptionHandling();

    expect(fn () => $this->get('/_debug/errors/http?m=abc-123'))
        ->toThrow(RuntimeException::class, 'errors-smoke:http:abc-123');
});

test('trasa debug frontendu renderuje stronę Inertia ze znacznikiem', function () {
    Route::middleware('web')->group(base_path('routes/debug.php'));

    $this->get('/_debug/errors/frontend?m=abc-123')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('debug/ErrorsSmoke')
            ->where('marker', 'abc-123'));
});

test('znacznik z nieprawidłowymi znakami jest czyszczony', function () {
    Route::middleware('web')->group(base_path('routes/debug.php'));

    $this->get('/_debug/errors/frontend?m='.urlencode('x"><script>'))
        ->assertInertia(fn (Assert $page) => $page->where('marker', 'xscript'));
});

test('errors:smoke odmawia poza środowiskiem local', function () {
    expect(Artisan::call('errors:smoke', ['marker' => 'abc']))->toBe(ErrorsSmokeCommand::FAILURE);
});

test('errors:smoke --queue wrzuca zadanie ze znacznikiem', function () {
    app()->detectEnvironment(fn () => 'local');
    Queue::fake();

    expect(Artisan::call('errors:smoke', ['marker' => 'abc', '--queue' => true]))->toBe(ErrorsSmokeCommand::SUCCESS);

    Queue::assertPushed(ErrorsSmokeJob::class, fn (ErrorsSmokeJob $job) => $job->marker === 'abc');
});

test('errors:smoke bez --queue rzuca wyjątek ze znacznikiem', function () {
    app()->detectEnvironment(fn () => 'local');
    $this->withoutExceptionHandling();

    expect(fn () => Artisan::call('errors:smoke', ['marker' => 'abc']))
        ->toThrow(RuntimeException::class, 'errors-smoke:cli:abc');
});

test('zadanie smoke rzuca wyjątek ze znacznikiem i nie jest ponawiane', function () {
    $job = new ErrorsSmokeJob('abc');

    expect($job->tries)->toBe(1)
        ->and(fn () => $job->handle())->toThrow(RuntimeException::class, 'errors-smoke:queue:abc');
});
