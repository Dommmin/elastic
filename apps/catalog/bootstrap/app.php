<?php

use App\Http\Controllers\ErrorTunnelController;
use App\Http\DomainErrorMessages;
use App\Http\Middleware\AssignTraceId;
use App\Http\Middleware\EnsureHasSeller;
use App\Http\Middleware\EnsureSellerApproved;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Support\ErrorTracking\QueueRetryFilter;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Ordering\Domain\OrderNotFound;
use SellerPortal\Application\Offer\OfferNotOwned;
use SellerPortal\Domain\Offer\OfferNotFound;
use SellerPortal\Domain\Seller\SellerNotFound;
use Sentry\Laravel\Integration;
use SharedKernel\DomainException;
use SharedKernel\InvalidValue;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Tunel błędów z przeglądarki do GlitchTipa (ADR-0009). Poza grupą `web`:
            // bez sesji, ciasteczek i CSRF, za to z limitem liczby żądań (`errors-tunnel`).
            Route::post('_errors', ErrorTunnelController::class)->middleware('throttle:errors-tunnel');

            // Trasy `make errors-smoke` wywołują błąd na życzenie: tylko lokalnie.
            if (app()->environment('local')) {
                Route::middleware('web')->group(base_path('routes/debug.php'));
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Pierwszy w łańcuchu: trace_id ma istnieć, zanim ruszy cokolwiek, co loguje.
        $middleware->prepend(AssignTraceId::class);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Webhook bramki nie ma sesji ani tokenu CSRF; wiarygodność daje podpis HMAC.
        $middleware->preventRequestForgery(except: ['webhooks/*']);

        $middleware->alias([
            'seller' => EnsureHasSeller::class,
            'seller.approved' => EnsureSellerApproved::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // GlitchTip dostaje dokładnie to, co Laravel raportuje, więc wszystkie
        // `dontReport*` poniżej (i wbudowane: 404, walidacja, autoryzacja) działają
        // też jako filtr błędów. Nie utrzymujemy drugiej listy wyjątków (ADR-0009).
        Integration::handles($exceptions);

        // Przejściowa porażka zadania, które zostanie ponowione, to nie błąd.
        $exceptions->dontReportWhen(
            fn (Throwable $e): bool => app(QueueRetryFilter::class)->willRetry($e),
        );

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Złamana reguła biznesowa w żądaniu HTTP to oczekiwany błąd
        // użytkownika (limit zmian ceny, konflikt wersji) — człowiek dostaje
        // komunikat, nie śmiecimy nim logu. `InvalidValue` zostaje zgłaszany:
        // wartość spoza zakresu VO, która przeszła walidację formularza, to
        // błąd w kodzie, który chcemy zobaczyć.
        // DLACZEGO tylko poza konsolą: w workerze (`queue:work`) i komendach
        // nikt komunikatu nie zobaczy. Job polityki, który padł na
        // `ConcurrencyConflict` po 3 próbach albo na `SellerNotFound`, musi
        // zostawić linię w logu, a nie tylko wiersz w `failed_jobs`
        // (RUNBOOK #040). Octane ustawia `APP_RUNNING_IN_CONSOLE=false`, więc
        // żądania HTTP na FrankenPHP/Swoole nie liczą się jako konsola.
        $exceptions->dontReportWhen(
            fn (Throwable $e): bool => $e instanceof DomainException
                && ! $e instanceof InvalidValue
                && ! app()->runningInConsole(),
        );

        // Cudza lub nieistniejąca oferta / sprzedawca / zamówienie → 404, nie 403: nie
        // zdradzamy, że zasób o tym ID istnieje. `map` działa przed renderem,
        // więc poniższy handler błędów domeny już ich nie widzi.
        foreach ([OfferNotOwned::class, OfferNotFound::class, SellerNotFound::class, OrderNotFound::class] as $notFound) {
            $exceptions->map($notFound, fn (DomainException $e) => new NotFoundHttpException($e->getMessage(), $e));
        }

        // Złamana reguła biznesowa to błąd formularza, nie 500: wracamy na
        // stronę z komunikatem pod kluczem `domain`. JSON-owe API dostaje
        // domyślną obsługę (null → dalej łańcuch Laravela).
        $exceptions->render(function (DomainException $e, Request $request) {
            $isInertia = $request->hasHeader('X-Inertia');

            if (! $isInertia && ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            return back()->withErrors(['domain' => DomainErrorMessages::for($e)]);
        });
    })->create();
