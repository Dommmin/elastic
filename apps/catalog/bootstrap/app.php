<?php

use App\Http\DomainErrorMessages;
use App\Http\Middleware\EnsureHasSeller;
use App\Http\Middleware\EnsureSellerApproved;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use SellerPortal\Application\Offer\OfferNotOwned;
use SellerPortal\Domain\Offer\OfferNotFound;
use SellerPortal\Domain\Seller\SellerNotFound;
use SellerPortal\Domain\Shared\DomainException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'seller' => EnsureHasSeller::class,
            'seller.approved' => EnsureSellerApproved::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Cudza lub nieistniejąca oferta / sprzedawca → 404, nie 403: nie
        // zdradzamy, że zasób o tym ID istnieje. `map` działa przed renderem,
        // więc poniższy handler błędów domeny już ich nie widzi.
        foreach ([OfferNotOwned::class, OfferNotFound::class, SellerNotFound::class] as $notFound) {
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
