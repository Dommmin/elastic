<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\CurrentSeller;
use App\Http\Requests\OfferVersionRequest;
use App\Http\Requests\Seller\StoreOfferRequest;
use App\Http\Requests\Seller\UpdateOfferDetailsRequest;
use App\Http\Requests\Seller\UpdateOfferPriceRequest;
use App\Http\Requests\Seller\UpdateOfferStockRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use SellerPortal\Application\Offer\CancelPendingPriceChange;
use SellerPortal\Application\Offer\CancelPendingPriceChangeHandler;
use SellerPortal\Application\Offer\ChangeOfferDetails;
use SellerPortal\Application\Offer\ChangeOfferDetailsHandler;
use SellerPortal\Application\Offer\ChangeOfferPrice;
use SellerPortal\Application\Offer\ChangeOfferPriceHandler;
use SellerPortal\Application\Offer\ChangeOfferStock;
use SellerPortal\Application\Offer\ChangeOfferStockHandler;
use SellerPortal\Application\Offer\DraftOffer;
use SellerPortal\Application\Offer\DraftOfferHandler;
use SellerPortal\Application\Offer\PublishOffer;
use SellerPortal\Application\Offer\PublishOfferHandler;
use SellerPortal\Application\Offer\SellerOfferAccess;
use SellerPortal\Application\Offer\WithdrawOffer;
use SellerPortal\Application\Offer\WithdrawOfferHandler;
use SellerPortal\Application\Port\Clock;
use SellerPortal\Domain\Offer\OfferAction;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Offer\PriceChangeDecision;
use SellerPortal\Domain\Offer\PriceChangePolicy;
use SellerPortal\Domain\Shared\UserId;
use SellerPortal\Infrastructure\ReadModel\SellerOfferDetails;
use SellerPortal\Infrastructure\ReadModel\SellerOfferList;

/**
 * Oferty zalogowanego sprzedawcy. Zapisy idą wyłącznie przez handlery
 * warstwy aplikacji — reguły (właściciel, aktywny sprzedawca, strażnik ceny,
 * wersja z formularza) siedzą tam i w agregacie, a ich wyjątki tłumaczy `bootstrap/app.php`
 * (404 dla cudzej oferty, błąd `domain` dla złamanej reguły).
 */
class SellerOfferController extends Controller
{
    public function index(Request $request, CurrentSeller $currentSeller, SellerOfferList $list): Response
    {
        $filter = $request->query('status');
        $filter = is_string($filter) && in_array($filter, SellerOfferList::FILTERS, true) ? $filter : null;

        return Inertia::render('seller/offers/Index', [
            'offers' => $list->paginate($currentSeller->of($request)['id'], $filter),
            'filters' => ['status' => $filter],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('seller/offers/Create');
    }

    public function store(StoreOfferRequest $request, DraftOfferHandler $handler): RedirectResponse
    {
        $offerId = $handler->handle(new DraftOffer(
            userId: self::userId($request),
            productId: (int) $request->validated('product_id'),
            priceCents: $request->priceCents(),
            stock: (int) $request->validated('stock'),
            condition: (string) $request->validated('condition'),
            shippingDays: (int) $request->validated('shipping_days'),
        ));

        self::toast('Szkic oferty zapisany');

        return redirect()->route('seller.offers.show', $offerId->value());
    }

    /**
     * DLACZEGO agregat obok modelu odczytu: przyciski powstają wyłącznie
     * z `SellerOfferAccess::sellerActions` (stan oferty + reguła dostępu
     * aktora), a licznik zmian ceny z tej samej polityki, którą domena stosuje
     * przy zapisie — UI nie powiela reguł. `forUser` przy okazji pilnuje
     * właściciela (cudza oferta → 404).
     */
    public function show(
        Request $request,
        int $offer,
        SellerOfferAccess $access,
        SellerOfferDetails $details,
        Clock $clock,
    ): Response {
        [$seller, $aggregate] = $access->forUser(UserId::fromInt(self::userId($request)), OfferId::fromInt($offer));

        return Inertia::render('seller/offers/Show', [
            'offer' => $details->find($seller->id()->value(), $offer) ?? abort(404),
            'allowed_actions' => array_map(fn (OfferAction $action): string => $action->value, $access->sellerActions($seller, $aggregate)),
            'price_guard' => [
                'threshold_percent' => PriceChangePolicy::THRESHOLD_PERCENT,
                'changes_left_today' => PriceChangePolicy::changesLeft($aggregate->recentPriceChanges(), $clock->now()),
            ],
            'timeline' => fn (): array => $details->timeline($offer),
        ]);
    }

    public function publish(OfferVersionRequest $request, int $offer, PublishOfferHandler $handler): RedirectResponse
    {
        $handler->handle(new PublishOffer(self::userId($request), $offer, $request->expectedVersion()));

        return self::backWithToast('Oferta opublikowana');
    }

    public function updatePrice(UpdateOfferPriceRequest $request, int $offer, ChangeOfferPriceHandler $handler): RedirectResponse
    {
        $decision = $handler->handle(new ChangeOfferPrice(self::userId($request), $offer, $request->priceCents(), $request->expectedVersion()));

        return match ($decision) {
            PriceChangeDecision::AppliedNow => self::backWithToast('Cena zmieniona'),
            PriceChangeDecision::AwaitingApproval => self::backWithToast('Zmiana o ponad 30% czeka na akceptację moderatora', 'info'),
        };
    }

    public function cancelPendingPrice(OfferVersionRequest $request, int $offer, CancelPendingPriceChangeHandler $handler): RedirectResponse
    {
        $handler->handle(new CancelPendingPriceChange(self::userId($request), $offer, $request->expectedVersion()));

        return self::backWithToast('Zgłoszenie zmiany ceny anulowane');
    }

    public function updateStock(UpdateOfferStockRequest $request, int $offer, ChangeOfferStockHandler $handler): RedirectResponse
    {
        $handler->handle(new ChangeOfferStock(self::userId($request), $offer, (int) $request->validated('stock'), $request->expectedVersion()));

        return self::backWithToast('Stan magazynowy zapisany');
    }

    public function updateDetails(UpdateOfferDetailsRequest $request, int $offer, ChangeOfferDetailsHandler $handler): RedirectResponse
    {
        $handler->handle(new ChangeOfferDetails(
            self::userId($request),
            $offer,
            (string) $request->validated('condition'),
            (int) $request->validated('shipping_days'),
            $request->expectedVersion(),
        ));

        return self::backWithToast('Szczegóły oferty zapisane');
    }

    public function withdraw(OfferVersionRequest $request, int $offer, WithdrawOfferHandler $handler): RedirectResponse
    {
        $handler->handle(new WithdrawOffer(self::userId($request), $offer, $request->expectedVersion()));

        return self::backWithToast('Oferta wycofana');
    }

    private static function userId(Request $request): int
    {
        return (int) $request->user()->getAuthIdentifier();
    }

    private static function toast(string $message, string $type = 'success'): void
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);
    }

    private static function backWithToast(string $message, string $type = 'success'): RedirectResponse
    {
        self::toast($message, $type);

        return back();
    }
}
