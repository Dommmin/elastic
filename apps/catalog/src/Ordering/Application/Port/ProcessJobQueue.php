<?php

namespace Ordering\Application\Port;

use Ordering\Domain\OrderId;
use Ordering\Domain\RefundId;

/**
 * Port kolejki kroków sagi zamówienia. Słuchacz zdarzenia tylko zleca krok,
 * wykonuje go worker. DLACZEGO port: implementacja wstawia job w tej samej
 * transakcji co zapis zamówienia (outbox komend), a Application nie zna Laravela.
 */
interface ProcessJobQueue
{
    public function reserveLines(OrderId $orderId): void;

    public function releaseReservations(OrderId $orderId): void;

    public function confirmReservations(OrderId $orderId): void;

    public function refund(OrderId $orderId, RefundId $refundId): void;
}
