<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\CurrentSeller;
use App\Http\Requests\Seller\CancelOrderRequest;
use App\Http\Requests\Seller\ShipOrderRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Ordering\Application\Order\CancelOrderBySeller;
use Ordering\Application\Order\CancelOrderBySellerHandler;
use Ordering\Application\Order\OrderAccess;
use Ordering\Application\Order\ShipOrder;
use Ordering\Application\Order\ShipOrderHandler;
use Ordering\Domain\Actor;
use Ordering\Domain\OrderAction;
use Ordering\Domain\OrderId;
use Ordering\Infrastructure\ReadModel\SellerOrderDetails;
use Ordering\Infrastructure\ReadModel\SellerOrderList;

/**
 * Zamówienia sprzedawcy: lista („Do wysłania” / „Wszystkie”), szczegóły,
 * wysyłka i anulowanie ze zwrotem. Cudze zamówienie to 404 z `OrderAccess`.
 */
class SellerOrderController extends Controller
{
    public function index(Request $request, CurrentSeller $currentSeller, SellerOrderList $list): Response
    {
        $tab = $request->query('tab') === SellerOrderList::ALL ? SellerOrderList::ALL : SellerOrderList::TO_SHIP;

        return Inertia::render('seller/orders/Index', [
            'tab' => $tab,
            'orders' => $list->forSeller($currentSeller->of($request)['id'], $tab),
        ]);
    }

    public function show(Request $request, int $order, OrderAccess $access, SellerOrderDetails $details): Response
    {
        $aggregate = $access->forSeller(self::userId($request), OrderId::fromInt($order));

        return Inertia::render('seller/orders/Show', [
            ...$details->for($order),
            'actions' => array_map(
                static fn (OrderAction $action): string => $action->value,
                $aggregate->allowedActionsFor(Actor::Seller),
            ),
        ]);
    }

    public function ship(ShipOrderRequest $request, int $order, ShipOrderHandler $handler): RedirectResponse
    {
        $handler->handle(new ShipOrder(self::userId($request), $order, $request->validated('tracking_number')));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Zamówienie oznaczone jako wysłane']);

        return redirect()->route('seller.orders.show', $order);
    }

    public function cancel(CancelOrderRequest $request, int $order, CancelOrderBySellerHandler $handler): RedirectResponse
    {
        $handler->handle(new CancelOrderBySeller(self::userId($request), $order, $request->validated('reason')));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Zamówienie anulowane, zwrot zlecony']);

        return redirect()->route('seller.orders.show', $order);
    }

    private static function userId(Request $request): int
    {
        return (int) $request->user()->getAuthIdentifier();
    }
}
