<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Ordering\Application\Order\CancelOrderByBuyer;
use Ordering\Application\Order\CancelOrderByBuyerHandler;
use Ordering\Application\Order\OrderAccess;
use Ordering\Application\Order\StartPayment;
use Ordering\Application\Order\StartPaymentHandler;
use Ordering\Domain\Actor;
use Ordering\Domain\BuyerId;
use Ordering\Domain\OrderAction;
use Ordering\Domain\OrderId;
use Ordering\Domain\OrderStatus;
use Ordering\Infrastructure\ReadModel\BuyerOrderDetails;
use Ordering\Infrastructure\ReadModel\BuyerOrderList;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Zamówienia kupującego: lista, szczegóły z osią czasu, płatność i anulowanie.
 * Cudze zamówienie to 404 z `OrderAccess` (bootstrap/app.php mapuje `OrderNotFound`).
 */
class OrderController extends Controller
{
    public function index(Request $request, BuyerOrderList $list): Response
    {
        return Inertia::render('shop/orders/Index', [
            'orders' => $list->forBuyer(self::userId($request)),
        ]);
    }

    public function show(Request $request, int $order, OrderAccess $access, BuyerOrderDetails $details): Response
    {
        $aggregate = $access->forBuyer(BuyerId::fromInt(self::userId($request)), OrderId::fromInt($order));

        // Powrót z bramki bywa szybszy niż webhook (R6): zamiast „Zapłać” (drugiej
        // płatności) pokazujemy baner „czekamy na potwierdzenie” i nie oferujemy `pay`.
        $awaiting = $request->query('payment') === 'returned' && $aggregate->status() === OrderStatus::AwaitingPayment;

        $data = $details->for($order, now()->toDateTimeImmutable());

        // Po terminie (a przed sweepem, który czeka jeszcze karencję webhooka) bramka i tak
        // nie przyjmie wpłaty, więc „Zapłać” znika; anulować nadal wolno.
        $pastDeadline = $aggregate->status() === OrderStatus::AwaitingPayment && $data['order']['seconds_to_deadline'] === 0;

        $actions = array_values(array_filter(
            array_map(static fn (OrderAction $action): string => $action->value, $aggregate->allowedActionsFor(Actor::Buyer)),
            static fn (string $action): bool => ! (($awaiting || $pastDeadline) && $action === OrderAction::Pay->value),
        ));

        return Inertia::render('shop/orders/Show', [
            ...$data,
            'actions' => $actions,
            'awaiting_confirmation' => $awaiting,
        ]);
    }

    public function pay(Request $request, int $order, StartPaymentHandler $handler): SymfonyResponse
    {
        $url = $handler->handle(new StartPayment(
            self::userId($request),
            $order,
            route('orders.show', [$order, 'payment' => 'returned']),
        ));

        // Zewnętrzny adres bramki: dla żądań Inertia to 409 + X-Inertia-Location (pełna nawigacja).
        return Inertia::location($url);
    }

    public function cancel(Request $request, int $order, CancelOrderByBuyerHandler $handler): RedirectResponse
    {
        $handler->handle(new CancelOrderByBuyer(self::userId($request), $order));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Zamówienie anulowane']);

        return redirect()->route('orders.show', $order);
    }

    private static function userId(Request $request): int
    {
        return (int) $request->user()->getAuthIdentifier();
    }
}
