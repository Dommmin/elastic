<?php

namespace App\Http\Controllers\Panel\Moderation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\ModerationReasonRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use SellerPortal\Application\Moderation\ApprovePriceChange;
use SellerPortal\Application\Moderation\ApprovePriceChangeHandler;
use SellerPortal\Application\Moderation\RejectPriceChange;
use SellerPortal\Application\Moderation\RejectPriceChangeHandler;
use SellerPortal\Infrastructure\ReadModel\PriceChangeQueue;

/**
 * Kolejka zmian ceny ponad próg strażnika. Decyzję (czy zmiana nadal czeka,
 * wersja oferty) podejmuje agregat; tu tylko HTTP → komenda.
 */
class PriceChangeModerationController extends Controller
{
    public function index(PriceChangeQueue $queue): Response
    {
        return Inertia::render('panel/moderation/PriceChanges', [
            'items' => $queue->all(),
        ]);
    }

    public function approve(Request $request, int $offer, ApprovePriceChangeHandler $handler): RedirectResponse
    {
        $handler->handle(new ApprovePriceChange(self::moderatorId($request), $offer));

        return self::backWithToast('Nowa cena zaakceptowana');
    }

    public function reject(ModerationReasonRequest $request, int $offer, RejectPriceChangeHandler $handler): RedirectResponse
    {
        $handler->handle(new RejectPriceChange(self::moderatorId($request), $offer, $request->validated('reason')));

        return self::backWithToast('Zmiana ceny odrzucona');
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
