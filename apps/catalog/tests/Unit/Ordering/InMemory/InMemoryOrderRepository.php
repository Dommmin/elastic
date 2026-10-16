<?php

namespace Tests\Unit\Ordering\InMemory;

use DateInterval;
use DateTimeImmutable;
use Ordering\Domain\Order;
use Ordering\Domain\OrderId;
use Ordering\Domain\OrderNotFound;
use Ordering\Domain\OrderRepository;
use SharedKernel\ConcurrencyConflict;

/**
 * Ten sam kontrakt co SqlOrderRepository (wersje, kopie przy zapisie i
 * odczycie, `idsDueForExpiry` po ID), więc testy handlerów zachowują się
 * jak na bazie.
 */
final class InMemoryOrderRepository implements OrderRepository
{
    /** @var array<int, Order> */
    private array $stored = [];

    private int $lastId = 0;

    private int $failingSaves = 0;

    /** @var array<int, \Throwable> */
    private array $brokenSaves = [];

    /**
     * Następne `$count` zapisów rzuci ConcurrencyConflict, jak przy wyścigu o wersję (test ponowień).
     */
    public function failNextSaves(int $count): void
    {
        $this->failingSaves = $count;
    }

    /**
     * Każdy zapis tego zamówienia rzuci `$error` (np. zepsuty wiersz), inne zapisują się normalnie.
     */
    public function breakSavesOf(OrderId $id, \Throwable $error): void
    {
        $this->brokenSaves[$id->value()] = $error;
    }

    public function nextIdentity(): OrderId
    {
        return OrderId::fromInt(++$this->lastId);
    }

    public function get(OrderId $id): Order
    {
        return isset($this->stored[$id->value()]) ? $this->copy($this->stored[$id->value()]) : throw new OrderNotFound;
    }

    public function save(Order $order): void
    {
        if (isset($this->brokenSaves[$order->id()->value()])) {
            throw $this->brokenSaves[$order->id()->value()];
        }

        if ($this->failingSaves > 0) {
            $this->failingSaves--;

            throw new ConcurrencyConflict;
        }

        $current = $this->stored[$order->id()->value()] ?? null;

        if (($current?->version() ?? 0) !== $order->version()) {
            throw new ConcurrencyConflict;
        }

        $this->stored[$order->id()->value()] = $this->copy($order, $order->version() + 1);
    }

    public function idsDueForExpiry(DateTimeImmutable $now, int $afterId, int $limit): array
    {
        $threshold = $now->sub(new DateInterval(Order::WEBHOOK_GRACE));
        $ids = [];

        foreach ($this->stored as $order) {
            if ($order->id()->value() > $afterId && $order->isDueForExpiry($now) && $order->paymentDeadline() <= $threshold) {
                $ids[] = $order->id()->value();
            }
        }

        sort($ids);

        return array_map(OrderId::fromInt(...), array_slice($ids, 0, $limit));
    }

    private function copy(Order $order, ?int $version = null): Order
    {
        return Order::reconstitute(
            $order->id(),
            $order->buyerId(),
            $order->sellerId(),
            $order->lines(),
            $order->status(),
            $order->placedAt(),
            $order->paymentDeadline(),
            $order->payment(),
            $order->refunds(),
            $order->rejection(),
            $order->cancellation(),
            $order->shipment(),
            $version ?? $order->version(),
        );
    }
}
