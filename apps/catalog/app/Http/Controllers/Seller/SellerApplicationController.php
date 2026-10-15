<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\CurrentSeller;
use App\Http\DomainErrorMessages;
use App\Http\Requests\Seller\ApplySellerRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use SellerPortal\Application\Seller\RegisterSeller;
use SellerPortal\Application\Seller\RegisterSellerHandler;
use SellerPortal\Application\SellerAlreadyRegistered;

/**
 * Zgłoszenie użytkownika jako sprzedawcy i podgląd stanu zgłoszenia.
 */
class SellerApplicationController extends Controller
{
    public function create(Request $request, CurrentSeller $currentSeller): Response|RedirectResponse
    {
        if ($currentSeller->of($request) !== null) {
            return redirect()->route('seller.status');
        }

        return Inertia::render('seller/Apply');
    }

    /**
     * DLACZEGO łapiemy `SellerAlreadyRegistered` tutaj: drugie zgłoszenie
     * (dwuklik, druga karta) nie jest błędem formularza — użytkownik ma już
     * profil, więc pokazujemy mu jego status zamiast wracać do formularza.
     */
    public function store(ApplySellerRequest $request, RegisterSellerHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new RegisterSeller(
                userId: (int) $request->user()->getAuthIdentifier(),
                name: $request->validated('name'),
                city: $request->validated('city'),
            ));
        } catch (SellerAlreadyRegistered $e) {
            return redirect()->route('seller.status')->withErrors(['domain' => DomainErrorMessages::for($e)]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Zgłoszenie wysłane — czeka na akceptację moderatora']);

        return redirect()->route('seller.status');
    }

    public function status(Request $request, CurrentSeller $currentSeller): Response
    {
        return Inertia::render('seller/Status', [
            'seller' => $currentSeller->of($request),
        ]);
    }
}
