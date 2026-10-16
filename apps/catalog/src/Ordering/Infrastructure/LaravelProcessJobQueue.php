<?php

namespace Ordering\Infrastructure;

use Ordering\Application\Port\ProcessJobQueue;
use Ordering\Domain\OrderId;
use Ordering\Domain\RefundId;
use Ordering\Infrastructure\Job\ConfirmOrderReservationsJob;
use Ordering\Infrastructure\Job\RefundPaymentJob;
use Ordering\Infrastructure\Job\ReleaseOrderReservationsJob;
use Ordering\Infrastructure\Job\ReserveOrderLinesJob;

/**
 * DLACZEGO połączenie przypięte (`queue.ordering_process_connection`, domyślnie `database`), a nie `queue.default`: krok sagi ma
 * powstać atomowo ze stanem zamówienia, a to daje tylko kolejka w tej samej
 * bazie — INSERT do `jobs` w bieżącej transakcji, wycofany razem z nią.
 * `after_commit` musi zostać `false` (config/queue.php), inaczej job wyszedłby
 * dopiero po commicie i atomowość by przepadła. Zmiana QUEUE_CONNECTION
 * (redis, sync) nie może rozpiąć tej gwarancji: Redis nie cofnąłby joba razem
 * z wycofanym złożeniem zamówienia.
 */
final class LaravelProcessJobQueue implements ProcessJobQueue
{
    private function connection(): string
    {
        return (string) config('queue.ordering_process_connection');
    }

    public function reserveLines(OrderId $orderId): void
    {
        ReserveOrderLinesJob::dispatch($orderId->value())->onConnection($this->connection());
    }

    public function releaseReservations(OrderId $orderId): void
    {
        ReleaseOrderReservationsJob::dispatch($orderId->value())->onConnection($this->connection());
    }

    public function confirmReservations(OrderId $orderId): void
    {
        ConfirmOrderReservationsJob::dispatch($orderId->value())->onConnection($this->connection());
    }

    public function refund(OrderId $orderId, RefundId $refundId): void
    {
        RefundPaymentJob::dispatch($orderId->value(), $refundId->value())->onConnection($this->connection());
    }
}
