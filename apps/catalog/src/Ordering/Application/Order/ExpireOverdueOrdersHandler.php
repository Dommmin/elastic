<?php

namespace Ordering\Application\Order;

use DateTimeImmutable;
use Ordering\Application\Port\Clock;
use Ordering\Application\Port\DomainEventDispatcher;
use Ordering\Application\Port\TransactionManager;
use Ordering\Domain\OrderId;
use Ordering\Domain\OrderRepository;
use SharedKernel\ConcurrencyConflict;

/**
 * Handler sweepu: paczki po 100 ID, każde zamówienie we własnej transakcji.
 *
 * DLACZEGO osobne transakcje: jeden konflikt albo błąd nie cofa wygaszenia
 * reszty, a blokady trwają krótko. DLACZEGO `ConcurrencyConflict` jest
 * pomijany zamiast ponawiany: przegrany wyścig to zwykle webhook płatności
 * (R3); następny przebieg za minutę wczyta świeży stan, a `expire` sam
 * zdecyduje (opłacone → no-op). DLACZEGO jedno `now` na przebieg: lista ID
 * i decyzja agregatu liczą karencję od tej samej chwili.
 */
final readonly class ExpireOverdueOrdersHandler
{
    public const int BATCH_SIZE = 100;

    public function __construct(
        private TransactionManager $transactions,
        private Clock $clock,
        private DomainEventDispatcher $events,
        private OrderRepository $orders,
    ) {}

    /**
     * @return int liczba wygaszonych zamówień
     */
    public function handle(ExpireOverdueOrders $command): int
    {
        $now = $this->clock->now();
        $expired = 0;
        $afterId = 0;

        do {
            $ids = $this->orders->idsDueForExpiry($now, $afterId, self::BATCH_SIZE);

            foreach ($ids as $orderId) {
                if ($this->expireOne($orderId, $now)) {
                    $expired++;
                }

                $afterId = $orderId->value();
            }
        } while (count($ids) === self::BATCH_SIZE);

        return $expired;
    }

    private function expireOne(OrderId $orderId, DateTimeImmutable $now): bool
    {
        try {
            return $this->transactions->run(function () use ($orderId, $now): bool {
                $order = $this->orders->get($orderId);

                // Indeks mógł być nieświeży albo webhook wygrał wcześniej.
                if (! $order->isDueForExpiry($now)) {
                    return false;
                }

                $order->expire($now);
                $this->orders->save($order);
                $this->events->dispatch($order->releaseEvents());

                return true;
            });
        } catch (ConcurrencyConflict) {
            return false;
        }
    }
}
