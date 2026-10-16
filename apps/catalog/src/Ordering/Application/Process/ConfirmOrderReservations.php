<?php

namespace Ordering\Application\Process;

use Ordering\Application\Port\StockReservations;
use Ordering\Domain\CancelledBy;
use Ordering\Domain\Order;
use Ordering\Domain\OrderId;
use Ordering\Domain\OrderRepository;
use Ordering\Domain\OrderStatus;

/**
 * Krok po `OrderPaid`: zamienia rezerwacje w sprzedaż (stan ofert spada, H1).
 *
 * DLACZEGO także `Shipped` i `Cancelled` przez sprzedawcę: job wykonuje się
 * później niż zdarzenie. Sprzedawca mógł już wysłać albo anulować opłacone
 * zamówienie; w obu przypadkach pieniądze przyszły, więc sztuki są sprzedane
 * (R7, H8: anulowanie sprzedawcy nie oddaje stanu). Bez zaksięgowanej wpłaty
 * nie ma czego potwierdzać: krok kończy się bez działania, zamiast sprzedać
 * towar, za który nikt nie zapłacił.
 *
 * Porażka techniczna leci dalej: job ponawia, a po wyczerpaniu prób zostaje
 * w `failed_jobs` (zamówienie świadomie nie śledzi potwierdzenia, spec sekcja 5).
 */
final readonly class ConfirmOrderReservations
{
    public function __construct(
        private OrderRepository $orders,
        private StockReservations $reservations,
    ) {}

    public function run(OrderId $orderId): void
    {
        $order = $this->orders->get($orderId);

        if (! $this->holdsSoldReservations($order)) {
            return;
        }

        $key = OrderReservationKey::for($orderId);

        foreach ($order->lines() as $line) {
            $this->reservations->confirm($key, $line->offerId());
        }
    }

    private function holdsSoldReservations(Order $order): bool
    {
        if ($order->payment() === null) {
            return false;
        }

        return match ($order->status()) {
            OrderStatus::Paid, OrderStatus::Shipped => true,
            OrderStatus::Cancelled => $order->cancellation()?->by === CancelledBy::Seller,
            default => false,
        };
    }
}
