<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\CurrentSeller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SellerPortal\Infrastructure\ReadModel\ProductLookup;

/**
 * Podpowiedzi produktu w formularzu szkicu (JSON dla `useHttp`, nie strona Inertia).
 */
class ProductLookupController extends Controller
{
    public function __invoke(Request $request, CurrentSeller $currentSeller, ProductLookup $lookup): JsonResponse
    {
        $validated = $request->validate(
            ['q' => ['required', 'string', 'min:2', 'max:100']],
            [
                'q.required' => 'Wpisz nazwę albo EAN produktu.',
                'q.min' => 'Wpisz co najmniej 2 znaki.',
                'q.max' => 'Zapytanie może mieć najwyżej 100 znaków.',
            ],
        );

        return response()->json($lookup->search($currentSeller->of($request)['id'], $validated['q']));
    }
}
