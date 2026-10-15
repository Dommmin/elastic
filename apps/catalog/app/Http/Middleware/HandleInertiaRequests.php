<?php

namespace App\Http\Middleware;

use App\Http\CurrentSeller;
use Illuminate\Http\Request;
use Inertia\Middleware;
use SellerPortal\Infrastructure\ReadModel\PriceChangeQueue;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                // Closure: liczone dopiero przy renderze strony (nie przy
                // przekierowaniach), jedno zapytanie dzielone z middleware `seller`.
                'seller' => fn () => app(CurrentSeller::class)->of($request),
            ],
            // Tylko admin: licznik przy „Zmiany cen” w menu. Closure, żeby
            // jedno `COUNT` szło dopiero przy renderze strony.
            'moderation' => fn (): ?array => $request->user()?->can('panel')
                ? ['pending_price_changes' => app(PriceChangeQueue::class)->count()]
                : null,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
