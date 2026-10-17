<?php

namespace Ordering\Application\Process;

use Ordering\Application\Port\Clock;
use Ordering\Application\Port\DomainEventDispatcher;
use Ordering\Application\Port\ReservationResult;
use Ordering\Application\Port\StockReservations;
use Ordering\Application\Port\TransactionManager;
use Ordering\Application\RetryOnConflict;
use Ordering\Domain\OfferId;
use Ordering\Domain\OrderId;
use Ordering\Domain\OrderLine;
use Ordering\Domain\OrderRepository;
use Ordering\Domain\OrderStatus;
use Ordering\Domain\RejectionReason;
use SharedKernel\ConcurrencyConflict;

/**
 * Krok po `OrderPlaced`: rezerwuje pozycje w ofertach, po jednej, w kolejności pozycji.
 *
 * Każda pozycja to dwie transakcje dwóch agregatów (H9, ADR-0007):
 * T1 rezerwacja w ofercie (port), T2 `markLineReserved` w zamówieniu.
 * Między nimi zamówienie może się zmienić (kupujący anuluje, sweep wygasza),
 * a proces może paść. Stąd trzy zasady:
 * - T1 jest idempotentne po kluczu `order-{id}`, więc ponowiony job (R2)
 *   nie zajmuje drugiej sztuki;
 * - T2 przy konflikcie wersji ponawia się na świeżym stanie (do 3 prób),
 *   potem wyjątek ponawia cały job;
 * - T2 sprawdza status jeszcze raz i sprząta po sobie (R1, niżej);
 * - krok, który zastaje zamówienie już poza `Placed`, sprząta za siebie
 *   z poprzedniego przebiegu (R1 + R2, niżej).
 *
 * Pierwsza odmowa kończy zamówienie (`reject`). Już zajęte sztuki zwalnia
 * dopiero krok po `OrderRejected` (`ReleaseOrderReservations` zwalnia
 * wszystkie pozycje), więc tu nie ma drugiej, równoległej ścieżki kompensacji.
 */
final readonly class ReserveOrderLines
{
    private const string MARKED = 'marked';

    private const string HELD_BY_ORDER = 'held_by_order';

    private const string ABANDONED = 'abandoned';

    public function __construct(
        private TransactionManager $transactions,
        private Clock $clock,
        private DomainEventDispatcher $events,
        private OrderRepository $orders,
        private StockReservations $reservations,
    ) {}

    /**
     * @throws ConcurrencyConflict gdy T2 przegra 3 razy z rzędu (job ponowi całość)
     */
    public function run(OrderId $orderId): void
    {
        $order = $this->orders->get($orderId);
        $key = OrderReservationKey::for($orderId);

        /*
         * DLACZEGO nie samo `return`, gdy zamówienie nie jest już `Placed`
         * (R1 + R2 naraz): poprzedni przebieg tego joba wysłał T1, kupujący
         * anulował, krok zwalniający przeszedł i niczego nie zastał, a dopiero
         * potem T1 się zatwierdziło. Worker padł przed T2, więc gałąź R1 niżej
         * się nie wykonała. Ponowiony job jest ostatnim, kto o tej rezerwacji
         * wie: zwalnia każdą pozycję (no-op, gdy nic nie wisi). Zamówieniu,
         * które rezerwacje trzyma (np. czeka na płatność), niczego nie oddajemy.
         */
        if ($order->status() !== OrderStatus::Placed) {
            if (! $order->holdsReservations()) {
                foreach ($order->lines() as $line) {
                    $this->reservations->release($key, $line->offerId());
                }
            }

            return;
        }

        foreach ($order->lines() as $line) {
            if ($line->isReserved()) {
                continue;
            }

            $result = $this->reservations->reserve($key, $line->offerId(), $line->quantity(), $line->unitPrice());

            if ($result !== ReservationResult::Reserved) {
                $this->reject($orderId, $line->offerId(), self::rejectionReason($result));

                return;
            }

            $outcome = $this->markReserved($orderId, $line);

            /*
             * DLACZEGO gałąź R1: zamówienie przestało być `Placed` między T1 a T2
             * (np. kupujący anulował). Krok zwalniający po anulowaniu mógł już
             * przejść i nie zastał tej rezerwacji, bo T1 jeszcze się nie
             * zatwierdziło. Nikt inny jej już nie zwolni, więc robi to ten krok;
             * bez tego sztuka wisiałaby zarezerwowana na zawsze. Kolejnych pozycji
             * nie rezerwujemy: zamówienie i tak ich nie potrzebuje.
             */
            if ($outcome === self::ABANDONED) {
                $this->reservations->release($key, $line->offerId());
            }

            if ($outcome !== self::MARKED) {
                return;
            }
        }
    }

    /**
     * T2. Zwraca, co zrobić z rezerwacją, którą T1 właśnie potwierdziło.
     *
     * DLACZEGO „inny status” nie zawsze znaczy „zwolnij”: ten sam job mógł
     * wykonać się dwa razy naraz (worker przekroczył `retry_after`). Jeśli
     * drugi przebieg doprowadził już zamówienie do `AwaitingPayment` (albo
     * dalej), nasze T1 trafiło w jego aktywną rezerwację (no-op po kluczu),
     * a jej zwolnienie oddałoby sprzedaną sztukę komuś innemu (overselling).
     * Zwalniamy tylko wtedy, gdy zamówienie samo z rezerwacji zrezygnowało.
     *
     * @return self::MARKED|self::HELD_BY_ORDER|self::ABANDONED
     */
    private function markReserved(OrderId $orderId, OrderLine $line): string
    {
        return RetryOnConflict::run(fn (): string => $this->transactions->run(function () use ($orderId, $line): string {
            $order = $this->orders->get($orderId);

            if ($order->status() !== OrderStatus::Placed) {
                return $order->holdsReservations() ? self::HELD_BY_ORDER : self::ABANDONED;
            }

            $order->markLineReserved($line->offerId(), $this->clock->now());
            $this->orders->save($order);
            $this->events->dispatch($order->releaseEvents());

            return self::MARKED;
        }));
    }

    /**
     * T2' po odmowie. Gdy zamówienie zdążyło przestać być `Placed`
     * (anulowane, wygasłe), odrzucać nie ma czego: zwolnienie zrobi krok po
     * tamtym zdarzeniu.
     */
    private function reject(OrderId $orderId, OfferId $offerId, RejectionReason $reason): void
    {
        RetryOnConflict::run(fn () => $this->transactions->run(function () use ($orderId, $offerId, $reason): void {
            $order = $this->orders->get($orderId);

            if ($order->status() !== OrderStatus::Placed) {
                return;
            }

            $order->reject($reason, $offerId, $this->clock->now());
            $this->orders->save($order);
            $this->events->dispatch($order->releaseEvents());
        }));
    }

    private static function rejectionReason(ReservationResult $result): RejectionReason
    {
        return match ($result) {
            ReservationResult::OutOfStock => RejectionReason::OutOfStock,
            ReservationResult::PriceChanged => RejectionReason::PriceChanged,
            ReservationResult::OfferUnavailable => RejectionReason::OfferUnavailable,
            ReservationResult::Reserved => throw new \LogicException('Udana rezerwacja nie jest powodem odmowy.'),
        };
    }
}
