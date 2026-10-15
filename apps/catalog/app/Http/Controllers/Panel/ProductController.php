<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\UpdateProductRequest;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Offer;
use App\Models\Product;
use App\Services\CategoryLabels;
use App\Services\Panel\EventTrace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use SellerPortal\Application\Offer\SellerOfferAccess;
use SellerPortal\Domain\Offer\OfferAction;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Offer\OfferRepository;
use SellerPortal\Domain\Seller\SellerRepository;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));

        $products = Product::query()
            ->with(['brand:id,name', 'category:id,path'])
            ->withCount(['offers as listed_offers_count' => fn ($query) => $query->listed()])
            ->withMin(['offers as min_price_cents' => fn ($query) => $query->listed()], 'price_cents')
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
            'listed_offers_count' => (int) $product->listed_offers_count,
            'min_price_cents' => $product->min_price_cents === null ? null : (int) $product->min_price_cents,
        ]);

        return Inertia::render('panel/Products', [
            'products' => $products,
            'filters' => ['q' => $q],
        ]);
    }

    public function edit(
        Product $product,
        EventTrace $eventTrace,
        OfferRepository $offerRepository,
        SellerRepository $sellerRepository,
        SellerOfferAccess $access,
    ): Response {
        // Wszystko poza `product` jako closure: polling drogi zdarzenia robi co
        // sekundę partial reload `only: ['trace']` i nie powinien przy tym
        // liczyć kategorii, marek ani ofert.
        return Inertia::render('panel/ProductEdit', [
            'product' => $product->only(['id', 'name', 'brand_id', 'category_id', 'version']),
            'brands' => fn () => Brand::query()->orderBy('name')->get(['id', 'name']),
            'categories' => fn () => $this->subcategoryOptions(),
            'offers' => fn () => $this->offerRows($product, $offerRepository, $sellerRepository, $access),
            'trace' => fn () => $eventTrace->forProduct($product),
        ]);
    }

    /**
     * DLACZEGO agregaty obok wiersza Eloquent: przyciski moderatora powstają
     * wyłącznie z `SellerOfferAccess::moderatorActions` (stan oferty z agregatu
     * plus stan sprzedawcy — ofert zablokowanego nie przywracamy), więc UI nie
     * powiela reguł przejść. Ofert na produkt jest kilka, odczyt per oferta
     * jest tu tańszy niż osobny model odczytu.
     *
     * @return list<array<string, mixed>>
     */
    private function offerRows(Product $product, OfferRepository $offerRepository, SellerRepository $sellerRepository, SellerOfferAccess $access): array
    {
        return $product->offers()
            ->with('seller:id,name,status')
            ->orderBy('id')
            ->get()
            ->map(function (Offer $offer) use ($offerRepository, $sellerRepository, $access): array {
                $aggregate = $offerRepository->get(OfferId::fromInt($offer->id));

                return [
                    'id' => $offer->id,
                    'seller' => $offer->seller?->name,
                    'seller_status' => $offer->seller?->status,
                    'price_cents' => $offer->price_cents,
                    'pending_price_cents' => $offer->pending_price_cents,
                    'stock' => $offer->stock,
                    'status' => $offer->status,
                    'suspension' => $offer->suspension_kind === null ? null : [
                        'kind' => $offer->suspension_kind,
                        'note' => $offer->suspension_note,
                    ],
                    'allowed_actions' => array_map(
                        fn (OfferAction $action): string => $action->value,
                        $access->moderatorActions($sellerRepository->get($aggregate->sellerId()), $aggregate),
                    ),
                    'version' => $offer->version,
                ];
            })
            ->all();
    }

    /**
     * @return Collection<int, array{id: int, label: string}>
     */
    private function subcategoryOptions(): Collection
    {
        $subcategories = Category::query()->whereNotNull('parent_id')->get(['id', 'path']);
        $labels = CategoryLabels::forPaths($subcategories->pluck('path')->filter()->all());

        return $subcategories
            ->map(fn (Category $category): array => [
                'id' => $category->id,
                'label' => $labels[$category->path] ?? (string) $category->path,
            ])
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->toBase();
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
