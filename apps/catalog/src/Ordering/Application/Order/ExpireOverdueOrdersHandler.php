<?php

namespace Ordering\Application\Order;

use DateTimeImmutable;
use Ordering\Application\Port\Clock;
use Ordering\Application\Port\DomainEventDispatcher;
use Ordering\Application\Port\TransactionManager;
use Ordering\Domain\OrderId;
use Ordering\Domain\OrderRepository;
use SharedKernel\ConcurrencyConflict;
use Throwable;

/**
 * Handler sweepu: paczki po 100 ID, każde zamówienie we własnej transakcji.
 *
 * DLACZEGO osobne transakcje: jeden konflikt albo błąd nie cofa wygaszenia
 * reszty, a blokady trwają krótko. DLACZEGO `ConcurrencyConflict` jest
 * pomijany zamiast ponawiany: przegrany wyścig to zwykle webhook płatności
 * (R3); następny przebieg za minutę wczyta świeży stan, a `expire` sam
 * zdecyduje (opłacone → no-op). DLACZEGO każdy inny błąd jest łapany
 * i zbierany do wyniku: jedno zepsute zamówienie (np. wiersz, którego nie da
 * się odtworzyć) wywracałoby sweep co minutę w tym samym miejscu i żadne
 * kolejne zamówienie by nie wygasło, a ich towar wisiałby zarezerwowany.
 * DLACZEGO jedno `now` na przebieg: lista ID i decyzja agregatu liczą
 * karencję od tej samej chwili.
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

    public function handle(ExpireOverdueOrders $command): ExpireOverdueOrdersResult
    {
        $now = $this->clock->now();
        $expired = 0;
        $failures = [];
        $afterId = 0;

        do {
            $ids = $this->orders->idsDueForExpiry($now, $afterId, self::BATCH_SIZE);

            foreach ($ids as $orderId) {
                try {
                    if ($this->expireOne($orderId, $now)) {
                        $expired++;
                    }
                } catch (Throwable $error) {
                    $failures[] = new ExpiryFailure($orderId, $error);
                }

                $afterId = $orderId->value();
            }
        } while (count($ids) === self::BATCH_SIZE);

        return new ExpireOverdueOrdersResult($expired, $failures);
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
