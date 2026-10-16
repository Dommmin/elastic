<?php

namespace Ordering\Application\Process;

use Ordering\Application\Port\ProcessJobQueue;
use Ordering\Domain\CancelledBy;
use Ordering\Domain\Event\OrderCancelled;
use Ordering\Domain\Event\OrderExpired;
use Ordering\Domain\Event\OrderPaid;
use Ordering\Domain\Event\OrderPlaced;
use Ordering\Domain\Event\OrderRejected;
use Ordering\Domain\Event\RefundRequested;

/**
 * Process manager zamówienia (ADR-0007): słucha zdarzeń `Order` i zleca
 * kolejny krok sagi. Stan procesu żyje w samym zamówieniu, tu nie ma
 * żadnego stanu.
 *
 * DLACZEGO słuchacze tylko kolejkują, a nie wykonują kroku: dispatcher woła
 * ich wewnątrz transakcji zapisu zamówienia, więc job powstaje atomowo
 * z nowym stanem (outbox komend). Wywołanie portalu albo bramki w tej
 * transakcji trzymałoby blokadę zamówienia na czas cudzej operacji, a jej
 * porażka cofałaby zmianę, która już się wydarzyła.
 */
final readonly class OrderProcessManager
{
    public function __construct(private ProcessJobQueue $jobs) {}

    public function onPlaced(OrderPlaced $event): void
    {
        $this->jobs->reserveLines($event->orderId);
    }

    public function onRejected(OrderRejected $event): void
    {
        $this->jobs->releaseReservations($event->orderId);
    }

    public function onExpired(OrderExpired $event): void
    {
        $this->jobs->releaseReservations($event->orderId);
    }

    /**
     * DLACZEGO anulowanie przez sprzedawcę NIE zwalnia rezerwacji (H8):
     * sprzedawca anuluje opłacone zamówienie zwykle dlatego, że towaru nie ma.
     * Oddanie sztuk do sprzedaży wystawiłoby je kolejnemu kupującemu, który
     * też by ich nie dostał. Rezerwacje takiego zamówienia potwierdza krok po
     * `OrderPaid` (R7); jeśli towar jednak jest, sprzedawca poprawi stan sam.
     * Zwrot pieniędzy idzie osobno, przez `RefundRequested`.
     */
    public function onCancelled(OrderCancelled $event): void
    {
        if ($event->by !== CancelledBy::Buyer) {
            return;
        }

        $this->jobs->releaseReservations($event->orderId);
    }

    public function onPaid(OrderPaid $event): void
    {
        $this->jobs->confirmReservations($event->orderId);
    }

    public function onRefundRequested(RefundRequested $event): void
    {
        $this->jobs->refund($event->orderId, $event->refundId);
    }
}
