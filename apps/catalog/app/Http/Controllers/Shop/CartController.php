<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Http\DomainErrorMessages;
use App\Http\Requests\Shop\AddToCartRequest;
use App\Http\Requests\Shop\CheckoutRequest;
use App\Http\Requests\Shop\UpdateCartItemRequest;
use App\Models\CartItem;
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
use Ordering\Infrastructure\ReadModel\CartView;

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

        // Zapis pod blokadą wiersza, ze ściśnięciem do limitu: dwa równoległe
        // żądania dodania nie mogą razem przekroczyć min(10, dostępne), choć
        // każde z osobna przeszło walidację.
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

            // Cena widziana przy ponownym dodaniu zastępuje starą: kupujący
            // właśnie patrzył na stronę produktu z aktualną ceną.
            $item->update([
                'quantity' => min($max, $item->quantity + $request->integer('quantity')),
                'added_price_cents' => $offer->price_cents,
            ]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Dodano do koszyka']);

        return back();
    }

    public function update(UpdateCartItemRequest $request, int $offer): RedirectResponse
    {
        $item = self::item($request, $offer);

        if ($request->has('quantity')) {
            $item->quantity = $request->integer('quantity');
        }

        if ($request->boolean('accept_price')) {
            // Zaakceptowanie nowej ceny = jawna zgoda kupującego (H5): od teraz formularz wyśle ją.
            $item->added_price_cents = (int) $item->offer()->value('price_cents');
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
        $cart = CartItem::query()->where('user_id', self::userId($request))->pluck('quantity', 'offer_id')->all();

        // Drugi klik / druga karta: koszyk opróżnił już pierwszy checkout (R9).
        if ($cart === []) {
            return redirect('/orders');
        }

        $lines = $request->cartLines();
        $sent = [];
        foreach ($lines as $line) {
            $sent[$line->offerId] = $line->quantity;
        }

        // Formularz musi odpowiadać koszykowi w bazie: zmiana w innej karcie
        // nie może po cichu złożyć czegoś, czego kupujący nie widział.
        if (count($lines) !== count($sent) || $sent != $cart) {
            return back()->withErrors(['cart' => 'Koszyk zmienił się w międzyczasie — odśwież stronę i sprawdź pozycje']);
        }

        try {
            $placed = $handler->handle(new PlaceOrdersFromCart(self::userId($request), $lines));
        } catch (PriceChangedSinceCart|OfferNoLongerAvailable|CannotBuyOwnOffer $e) {
            // Nic nie powstało (odmowy są przed zapisem); strona koszyka z
            // świeżym `CartView` pokaże, które pozycje się zmieniły.
            return back()->withErrors(['cart' => DomainErrorMessages::for($e)]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Złożono '.self::ordersPhrase(count($placed))]);

        return redirect('/orders');
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
