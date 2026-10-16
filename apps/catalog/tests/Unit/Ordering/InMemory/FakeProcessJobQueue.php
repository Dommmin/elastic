<?php

namespace Tests\Unit\Ordering\InMemory;

use Ordering\Application\Port\ProcessJobQueue;
use Ordering\Domain\OrderId;
use Ordering\Domain\RefundId;

/**
 * Kolejka sagi w pamięci: tylko zapisuje zlecone kroki. Test sam decyduje,
 * kiedy i w jakiej kolejności je wykonać (`take`), co pozwala ułożyć
 * dowolny przeplot kroków w testach wyścigów.
 */
final class FakeProcessJobQueue implements ProcessJobQueue
{
    /** @var list<array{job: string, orderId: OrderId, refundId: ?RefundId}> */
    public array $jobs = [];

    public function reserveLines(OrderId $orderId): void
    {
        $this->push('reserveLines', $orderId);
    }

    public function releaseReservations(OrderId $orderId): void
    {
        $this->push('releaseReservations', $orderId);
    }

    public function confirmReservations(OrderId $orderId): void
    {
        $this->push('confirmReservations', $orderId);
    }

    public function refund(OrderId $orderId, RefundId $refundId): void
    {
        $this->push('refund', $orderId, $refundId);
    }

    /**
     * Zwraca zlecone kroki i opróżnia kolejkę.
     *
     * @return list<array{job: string, orderId: OrderId, refundId: ?RefundId}>
     */
    public function take(): array
    {
        $jobs = $this->jobs;
        $this->jobs = [];

        return $jobs;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(static fn (array $job): string => $job['job'], $this->jobs);
    }

    private function push(string $job, OrderId $orderId, ?RefundId $refundId = null): void
    {
        $this->jobs[] = ['job' => $job, 'orderId' => $orderId, 'refundId' => $refundId];
    }
}
