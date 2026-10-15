<?php

namespace App\Http\Controllers\Panel\Moderation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\SuspendOfferRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use SellerPortal\Application\Moderation\ReinstateOffer;
use SellerPortal\Application\Moderation\ReinstateOfferHandler;
use SellerPortal\Application\Moderation\SuspendOffer;
use SellerPortal\Application\Moderation\SuspendOfferHandler;

/**
 * Zawieszanie i przywracanie ofert przez moderatora (wiersz oferty na stronie
 * produktu w panelu). Nieistniejąca oferta → `OfferNotFound` → 404, odmowa
 * agregatu → błąd `domain` — oba tłumaczy `bootstrap/app.php`.
 */
class OfferModerationController extends Controller
{
    public function suspend(SuspendOfferRequest $request, int $offer, SuspendOfferHandler $handler): RedirectResponse
    {
        $handler->handle(new SuspendOffer(self::moderatorId($request), $offer, $request->validated('note')));

        return self::backWithToast('Oferta zawieszona');
    }

    public function reinstate(Request $request, int $offer, ReinstateOfferHandler $handler): RedirectResponse
    {
        $handler->handle(new ReinstateOffer(self::moderatorId($request), $offer));

        return self::backWithToast('Oferta przywrócona');
    }

    private static function moderatorId(Request $request): int
    {
        return (int) $request->user()->getAuthIdentifier();
    }

    private static function backWithToast(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
