<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\AddToCartRequest;
use App\Models\Offer;
use App\Models\Product;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Strona produktu dla kupującego: oferty `listed` czytane z bazy (nie z ES),
 * bo cena i dostępność muszą być aktualne w chwili dodania do koszyka.
 */
class ProductPageController extends Controller
{
    public function show(Request $request, Product $product): Response
    {
        $userId = $request->user()?->getAuthIdentifier();

        $offers = Offer::query()
            ->withAvailable()
            ->listed()
            ->where('product_id', $product->id)
            ->with('seller:id,name,user_id')
            ->orderBy('price_cents')
            ->orderBy('offers.id')
            ->get()
            ->map(fn (Offer $offer): array => [
                'id' => $offer->id,
                'seller' => ['id' => $offer->seller->id, 'name' => $offer->seller->name],
                'price_cents' => $offer->price_cents,
                'condition' => $offer->condition,
                'shipping_days' => $offer->shipping_days,
                'available' => (int) $offer->available,
                'max_quantity' => AddToCartRequest::maxQuantity($offer),
                'is_own' => $userId !== null && $offer->seller->user_id === $userId,
            ])
            ->all();

        return Inertia::render('shop/Product', [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'description' => $product->description,
                'brand' => $product->brand?->name,
            ],
            'offers' => $offers,
        ]);
    }
}
