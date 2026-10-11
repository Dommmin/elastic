<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\UpdateProductRequest;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\CategoryLabels;
use Illuminate\Http\RedirectResponse;
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

    public function edit(Product $product): Response
    {
        $subcategories = Category::query()->whereNotNull('parent_id')->get(['id', 'path']);
        $labels = CategoryLabels::forPaths($subcategories->pluck('path')->filter()->all());

        $categories = $subcategories
            ->map(fn (Category $category): array => [
                'id' => $category->id,
                'label' => $labels[$category->path] ?? (string) $category->path,
            ])
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return Inertia::render('panel/ProductEdit', [
            'product' => $product->only(['id', 'name', 'brand_id', 'category_id', 'version']),
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name']),
            'categories' => $categories,
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $validated = $request->validated();

        // `updateWithOutbox` zawsze zapisuje zdarzenie i podbija wersję, więc
        // zapis bez zmian (np. dwuklik) odsiewamy tutaj, nie wypychając do ES pustych zdarzeń.
        if (! $product->fill($validated)->isDirty()) {
            Inertia::flash('status', 'Bez zmian');

            return back();
        }

        $product->updateWithOutbox($validated, 'product.updated');
        Inertia::flash('status', 'Zapisano, zdarzenie w drodze');

        return back();
    }
}
