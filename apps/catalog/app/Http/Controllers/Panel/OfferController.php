<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\UpdateOfferRequest;
use App\Models\Offer;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class OfferController extends Controller
{
    // Oba zapisy wracają na stronę produktu *oferty*, a nie na `back()`:
    // oferta może należeć do innego produktu niż strona, z której przyszło żądanie.
    public function update(UpdateOfferRequest $request, Offer $offer): RedirectResponse
    {
        $attributes = $request->attributesForUpdate();

        // `updateWithOutbox` zawsze zapisuje zdarzenie, więc brak zmian odsiewamy tutaj.
        if (! $offer->fill($attributes)->isDirty()) {
            Inertia::flash('status', 'Bez zmian');
        } else {
            $offer->updateWithOutbox($attributes);
            Inertia::flash('status', 'Zapisano, zdarzenie w drodze');
        }

        return redirect()->route('panel.products.edit', $offer->product_id);
    }

    public function deactivate(Offer $offer): RedirectResponse
    {
        // `deactivateWithOutbox` pisze zdarzenie bezwarunkowo; ponowny klik nie może go dublować.
        if (! $offer->active) {
            Inertia::flash('status', 'Oferta już nieaktywna');
        } else {
            $offer->deactivateWithOutbox();
            Inertia::flash('status', 'Oferta dezaktywowana, zdarzenie w drodze');
        }

        return redirect()->route('panel.products.edit', $offer->product_id);
    }
}
