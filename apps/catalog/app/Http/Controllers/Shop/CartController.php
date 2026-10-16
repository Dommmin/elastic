<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Http\DomainErrorMessages;
use App\Http\Requests\Shop\AddToCartRequest;
use App\Http\Requests\Shop\CheckoutRequest;
use App\Http\Requests\Shop\UpdateCartItemRequest;
use App\Models\CartItem;
use App\Models\Offer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Ordering\Application\Order\CannotBuyOwnOffer;
use Ordering\Application\Order\OfferNoLongerAvailable;
use Ordering\Application\Order\PlaceOrdersFromCart;
use Ordering\Application\Order\PlaceOrdersFromCartHandler;
use Ordering\Application\Order\PriceChangedSinceCart;
use Ordering\Domain\InvalidOrderLines;
use Ordering\Infrastructure\ReadModel\CartView;
use SharedKernel\DomainException;

/**
 * Koszyk kupującego i składanie zamówień. Koszyk to dane robocze (bez agregatu);
 * zamówienia powstają wyłącznie w `PlaceOrdersFromCartHandler`.
 */
class CartController extends Controller
{
    public function index(Request $request, CartView $view): Response
    {
        $groups = $view->forBuyer(self::userId($request));
        $lines = array_merge(...array_column($groups, 'lines') ?: [[]]);

        return Inertia::render('shop/Cart', [
            'groups' => $groups,
            'orders_count' => count($groups),
            'total_cents' => array_sum(array_column($groups, 'subtotal_cents')),
            'has_blockers' => array_any($lines, fn (array $line): bool => $line['status'] !== CartView::OK),
        ]);
    }

    public function store(AddToCartRequest $request): RedirectResponse
    {
        $offer = $request->offer();
        $max = AddToCartRequest::maxQuantity($offer);

        // Zapis pod blokadą istniejącego wiersza, ze ściśnięciem do limitu:
        // równoległe dodania istniejącej pozycji nie przekroczą min(10, dostępne).
        // Pierwsze dodanie nie ma czego zablokować, więc dwa równoległe mogą
        // zderzyć się na unikalnym (user_id, offer_id): przegrany ponawia raz
        // i trafia już na istniejący wiersz.
        try {
            $this->upsert($request, $offer, $max);
        } catch (UniqueConstraintViolationException) {
            $this->upsert($request, $offer, $max);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Dodano do koszyka']);

        return back();
    }

    private function upsert(AddToCartRequest $request, Offer $offer, int $max): void
    {
        DB::transaction(function () use ($request, $offer, $max): void {
            $item = CartItem::query()
                ->where('user_id', self::userId($request))
                ->where('offer_id', $offer->id)
                ->lockForUpdate()
                ->first();

            if ($item === null) {
                CartItem::create([
                    'user_id' => self::userId($request),
                    'offer_id' => $offer->id,
                    'quantity' => min($max, $request->integer('quantity')),
                    'added_price_cents' => $offer->price_cents,
                ]);

                return;
            }

            // `added_price_cents` zostaje: cena zaakceptowana przy pierwszym
            // dodaniu. Zmiana ceny ma wyjść w koszyku jako `price_changed`,
            // a nie zniknąć przy kolejnym dodaniu.
            $item->update(['quantity' => min($max, $item->quantity + $request->integer('quantity'))]);
        });
    }

    public function update(UpdateCartItemRequest $request, int $offer): RedirectResponse
    {
        $item = self::item($request, $offer);

        if ($request->has('quantity')) {
            $item->quantity = $request->integer('quantity');
        }

        if ($request->has('accept_price_cents')) {
            // Zgoda dotyczy ceny, którą kupujący widział; jeśli oferta zdążyła
            // zmienić cenę ponownie, nie akceptujemy po cichu nowej (H5).
            $current = (int) $item->offer()->value('price_cents');

            if ($request->integer('accept_price_cents') !== $current) {
                return back()->withErrors(['accept_price_cents' => 'Cena oferty zmieniła się ponownie — sprawdź nową cenę']);
            }

            $item->added_price_cents = $current;
        }

        $item->save();

        return back();
    }

    public function destroy(Request $request, int $offer): RedirectResponse
    {
        self::item($request, $offer)->delete();

        return back();
    }

    public function checkout(CheckoutRequest $request, PlaceOrdersFromCartHandler $handler): RedirectResponse
    {
        // Jedna transakcja obejmuje odczyt koszyka pod blokadą i handler
        // (jego transakcja staje się savepointem): drugie równoległe żądanie
        // czeka na blokadzie, a po commicie widzi już pusty koszyk (R9).
        $result = DB::transaction(function () use ($request, $handler) {
            $cart = CartItem::query()->where('user_id', self::userId($request))->lockForUpdate()->pluck('quantity', 'offer_id')->all();

            if ($cart === []) {
                return null;
            }

            $lines = $request->cartLines();
            $sent = [];
            foreach ($lines as $line) {
                $sent[$line->offerId] = $line->quantity;
            }

            // Formularz musi odpowiadać koszykowi w bazie: zmiana w innej karcie
            // nie może po cichu złożyć czegoś, czego kupujący nie widział.
            if (count($lines) !== count($sent) || $sent != $cart) {
                return 'mismatch';
            }

            try {
                return $handler->handle(new PlaceOrdersFromCart(self::userId($request), $lines));
            } catch (PriceChangedSinceCart|OfferNoLongerAvailable|CannotBuyOwnOffer|InvalidOrderLines $e) {
                // Odmowy są przed zapisem (a `InvalidOrderLines` z `Order::place`
                // cofa transakcję), więc nic nie powstało; strona koszyka ze
                // świeżym `CartView` pokaże, które pozycje się zmieniły.
                return $e;
            }
        });

        if ($result === null) {
            return redirect()->route('orders.index');
        }

        if ($result === 'mismatch') {
            return back()->withErrors(['cart' => 'Koszyk zmienił się w międzyczasie — odśwież stronę i sprawdź pozycje']);
        }

        if ($result instanceof DomainException) {
            return back()->withErrors(['cart' => DomainErrorMessages::for($result)]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Złożono '.self::ordersPhrase(count($result))]);

        return redirect()->route('orders.index');
    }

    private static function item(Request $request, int $offer): CartItem
    {
        return CartItem::query()
            ->where('user_id', self::userId($request))
            ->where('offer_id', $offer)
            ->firstOrFail();
    }

    private static function userId(Request $request): int
    {
        return (int) $request->user()->getAuthIdentifier();
    }

    private static function ordersPhrase(int $count): string
    {
        $few = $count % 10 >= 2 && $count % 10 <= 4 && ($count % 100 < 10 || $count % 100 >= 20);

        return $count.' '.match (true) {
            $count === 1 => 'zamówienie',
            $few => 'zamówienia',
            default => 'zamówień',
        };
    }
}
