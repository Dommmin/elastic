<?php

namespace App\Http\Controllers\Panel\Moderation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\SellerDecisionReasonRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use SellerPortal\Application\Seller\ApproveSeller;
use SellerPortal\Application\Seller\ApproveSellerHandler;
use SellerPortal\Application\Seller\BlockSeller;
use SellerPortal\Application\Seller\BlockSellerHandler;
use SellerPortal\Application\Seller\RejectSeller;
use SellerPortal\Application\Seller\RejectSellerHandler;
use SellerPortal\Application\Seller\UnblockSeller;
use SellerPortal\Application\Seller\UnblockSellerHandler;
use SellerPortal\Domain\Seller\SellerStatus;
use SellerPortal\Infrastructure\ReadModel\PendingSellers;
use SellerPortal\Infrastructure\ReadModel\SellerDirectory;

/**
 * Moderacja sprzedawców w panelu. Kontroler tylko tłumaczy HTTP na komendy;
 * czy przejście jest dozwolone, rozstrzyga agregat, a jego odmowę zamienia
 * na komunikat handler wyjątków w `bootstrap/app.php`.
 */
class SellerModerationController extends Controller
{
    public function index(Request $request, PendingSellers $pending, SellerDirectory $directory): Response
    {
        // Nieznany status traktujemy jak brak filtra zamiast 422 — to tylko widok listy.
        $status = SellerStatus::tryFrom((string) $request->query('status', ''))?->value;

        return Inertia::render('panel/sellers/Index', [
            'pending' => $pending->all(),
            'sellers' => $directory->paginate($status),
            'filters' => ['status' => $status],
        ]);
    }

    public function approve(Request $request, int $seller, ApproveSellerHandler $handler): RedirectResponse
    {
        $handler->handle(new ApproveSeller($seller, $this->moderatorId($request)));

        return $this->done('Sprzedawca zaakceptowany');
    }

    public function reject(SellerDecisionReasonRequest $request, int $seller, RejectSellerHandler $handler): RedirectResponse
    {
        $handler->handle(new RejectSeller($seller, $this->moderatorId($request), $request->validated('reason')));

        return $this->done('Zgłoszenie odrzucone');
    }

    public function block(SellerDecisionReasonRequest $request, int $seller, BlockSellerHandler $handler): RedirectResponse
    {
        $handler->handle(new BlockSeller($seller, $this->moderatorId($request), $request->validated('reason')));

        return $this->done('Sprzedawca zablokowany, jego oferty zostaną zawieszone');
    }

    public function unblock(Request $request, int $seller, UnblockSellerHandler $handler): RedirectResponse
    {
        $handler->handle(new UnblockSeller($seller, $this->moderatorId($request)));

        return $this->done('Sprzedawca odblokowany, jego oferty wrócą do sprzedaży');
    }

    private function moderatorId(Request $request): int
    {
        return (int) $request->user()->getAuthIdentifier();
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
