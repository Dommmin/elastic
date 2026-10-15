<?php

namespace App\Http\Middleware;

use App\Http\CurrentSeller;
use Closure;
use Illuminate\Http\Request;
use SellerPortal\Domain\Seller\SellerStatus;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wpuszcza do portalu sprzedawcę zaakceptowanego przez moderatora.
 *
 * DLACZEGO zablokowany przechodzi: widzi swoje oferty tylko do odczytu
 * z banerem o blokadzie (akcje i tak odrzuci domena), zamiast tracić wgląd
 * w konto. Oczekujący i odrzucony nie mają czego oglądać poza statusem.
 */
class EnsureSellerApproved
{
    public function __construct(private CurrentSeller $currentSeller) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $seller = $this->currentSeller->of($request);

        if ($seller === null) {
            return redirect()->route('seller.apply');
        }

        $status = SellerStatus::tryFrom($seller['status']);

        if ($status !== SellerStatus::Active && $status !== SellerStatus::Blocked) {
            return redirect()->route('seller.status');
        }

        return $next($request);
    }
}
