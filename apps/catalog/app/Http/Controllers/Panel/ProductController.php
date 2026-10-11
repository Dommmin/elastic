<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\CategoryLabels;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));

        $products = Product::query()
            ->with(['brand:id,name', 'category:id,path'])
            ->withCount(['offers as active_offers_count' => fn ($query) => $query->where('active', true)])
            ->withMin(['offers as min_price_cents' => fn ($query) => $query->where('active', true)], 'price_cents')
            ->when($q !== '', function ($query) use ($q) {
                // `ILIKE` nie istnieje w SQLite (testy), `lower() like` działa w obu bazach.
                ctype_digit($q)
                    ? $query->where('id', (int) $q)
                    : $query->whereRaw('lower(name) like ?', ['%'.mb_strtolower($q).'%']);
            })
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        $labels = CategoryLabels::forPaths(
            $products->getCollection()->pluck('category.path')->filter()->all()
        );

        $products->through(fn (Product $product): array => [
            'id' => $product->id,
            'name' => $product->name,
            'brand' => $product->brand?->name,
            'category_label' => $labels[$product->category?->path] ?? '',
            'active_offers_count' => (int) $product->active_offers_count,
            'min_price_cents' => $product->min_price_cents === null ? null : (int) $product->min_price_cents,
        ]);

        return Inertia::render('panel/Products', [
            'products' => $products,
            'filters' => ['q' => $q],
        ]);
    }
}
