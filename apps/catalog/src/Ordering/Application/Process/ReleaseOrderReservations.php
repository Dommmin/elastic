<?php

namespace Ordering\Application\Process;

use Ordering\Application\Port\StockReservations;
use Ordering\Domain\OrderId;
use Ordering\Domain\OrderRepository;

/**
 * Kompensacja po `OrderRejected`, `OrderExpired` i `OrderCancelled(by: Buyer)`:
 * oddaje ofertom sztuki zarezerwowane przez zamówienie.
 *
 * DLACZEGO zwalniamy KAŻDĄ pozycję, a nie tylko oznaczone jako `Reserved`:
 * status pozycji w zamówieniu może być w tyle za ofertą. Awaria między T1
 * (rezerwacja w ofercie) a T2 (zapis w zamówieniu) zostawia sztukę zajętą
 * przy pozycji wciąż `Pending` (R2, R10), a odmowa rezerwacji przerywa
 * pętlę, gdy wcześniejsze pozycje są już zajęte. Zwolnienie nieistniejącej
 * rezerwacji to w ofercie no-op, więc „wszystkie” jest zawsze bezpieczne,
 * a „tylko Reserved” gubiłoby sztuki na zawsze.
 *
 * Bez transakcji i bez zapisu zamówienia: każde zwolnienie to osobna
 * transakcja oferty, a ponowiony job powtarza je bez szkody.
 */
final readonly class ReleaseOrderReservations
{
    public function __construct(
        private OrderRepository $orders,
        private StockReservations $reservations,
    ) {}

    public function run(OrderId $orderId): void
    {
        $order = $this->orders->get($orderId);
        $key = OrderReservationKey::for($orderId);

        foreach ($order->lines() as $line) {
            $this->reservations->release($key, $line->offerId());
        }
    }
}
