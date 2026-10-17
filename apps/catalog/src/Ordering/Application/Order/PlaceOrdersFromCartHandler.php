<?php

namespace Ordering\Application\Order;

use Ordering\Application\Port\BuyerCart;
use Ordering\Application\Port\Clock;
use Ordering\Application\Port\DomainEventDispatcher;
use Ordering\Application\Port\OfferSnapshot;
use Ordering\Application\Port\OfferSnapshots;
use Ordering\Application\Port\TransactionManager;
use Ordering\Domain\BuyerId;
use Ordering\Domain\LineQuantity;
use Ordering\Domain\OfferId;
use Ordering\Domain\Order;
use Ordering\Domain\OrderId;
use Ordering\Domain\OrderLine;
use Ordering\Domain\OrderRepository;
use Ordering\Domain\SellerId;

/**
 * Handler komendy PlaceOrdersFromCart: sprawdź koszyk z ofertami → pogrupuj
 * po sprzedawcy → w jednej transakcji złóż N zamówień i usuń z koszyka złożone oferty.
 *
 * DLACZEGO wszystkie odmowy przed zapisem: kupujący oczekuje „wszystko albo
 * nic”, a każda odmowa niesie pełną listę pozycji, żeby poprawił koszyk raz.
 * DLACZEGO N agregatów w jednej transakcji nie łamie zasady „jeden agregat
 * na transakcję”: zasada chroni przed rywalizacją o istniejące agregaty,
 * a nowych zamówień nikt inny jeszcze nie widzi (spec, sekcja 4). Z tego
 * samego powodu nie ma tu `RetryOnConflict`: nowy agregat nie ma z kim
 * przegrać wyścigu o wersję.
 * DLACZEGO bez sprawdzania dostępności: decyduje o niej rezerwacja w sadze.
 */
final readonly class PlaceOrdersFromCartHandler
{
    public function __construct(
        private TransactionManager $transactions,
        private Clock $clock,
        private DomainEventDispatcher $events,
        private OrderRepository $orders,
        private OfferSnapshots $offers,
        private BuyerCart $cart,
    ) {}

    /**
     * @return list<OrderId> w kolejności rosnącego ID sprzedawcy
     *
     * @throws CartEmpty
     * @throws OfferNoLongerAvailable
     * @throws CannotBuyOwnOffer
     * @throws PriceChangedSinceCart
     */
    public function handle(PlaceOrdersFromCart $command): array
    {
        if ($command->lines === []) {
            throw new CartEmpty;
        }

        $buyerId = BuyerId::fromInt($command->buyerUserId);
        $offerIds = array_map(
            static fn (CartLine $line): OfferId => OfferId::fromInt($line->offerId),
            $command->lines,
        );
        $snapshots = $this->offers->forOffers($offerIds);

        $this->guardAvailable($command->lines, $snapshots);
        $this->guardNotOwn($command, $snapshots);
        $this->guardPrices($command->lines, $snapshots);

        $groups = $this->groupBySeller($command->lines, $snapshots);

        return $this->transactions->run(function () use ($buyerId, $groups, $offerIds): array {
            $now = $this->clock->now();
            $placed = [];

            foreach ($groups as $sellerId => $lines) {
                $order = Order::place($this->orders->nextIdentity(), $buyerId, SellerId::fromInt($sellerId), $lines, $now);
                $this->orders->save($order);
                $placed[] = $order;
            }

            $this->cart->clear($buyerId, $offerIds);

            foreach ($placed as $order) {
                $this->events->dispatch($order->releaseEvents());
            }

            return array_map(static fn (Order $order): OrderId => $order->id(), $placed);
        });
    }

    /**
     * @param  list<CartLine>  $lines
     * @param  array<int, OfferSnapshot>  $snapshots
     *
     * @throws OfferNoLongerAvailable
     */
    private function guardAvailable(array $lines, array $snapshots): void
    {
        $missing = [];

        foreach ($lines as $line) {
            $snapshot = $snapshots[$line->offerId] ?? null;

            if ($snapshot === null || ! $snapshot->listed) {
                $missing[] = $line->offerId;
            }
        }

        if ($missing !== []) {
            throw new OfferNoLongerAvailable($missing);
        }
    }

    /**
     * @param  array<int, OfferSnapshot>  $snapshots
     *
     * @throws CannotBuyOwnOffer
     */
    private function guardNotOwn(PlaceOrdersFromCart $command, array $snapshots): void
    {
        $own = [];

        foreach ($command->lines as $line) {
            if ($snapshots[$line->offerId]->sellerUserId === $command->buyerUserId) {
                $own[] = $line->offerId;
            }
        }

        if ($own !== []) {
            throw new CannotBuyOwnOffer($own);
        }
    }

    /**
     * @param  list<CartLine>  $lines
     * @param  array<int, OfferSnapshot>  $snapshots
     *
     * @throws PriceChangedSinceCart
     */
    private function guardPrices(array $lines, array $snapshots): void
    {
        $changes = [];

        foreach ($lines as $line) {
            $current = $snapshots[$line->offerId]->price->cents();

            if ($current !== $line->expectedUnitPriceCents) {
                $changes[] = new PriceChange($line->offerId, $line->expectedUnitPriceCents, $current);
            }
        }

        if ($changes !== []) {
            throw new PriceChangedSinceCart($changes);
        }
    }

    /**
     * Cena i nazwa pochodzą ze snapshotu (zamrożone w pozycji), ilość z koszyka.
     *
     * @param  list<CartLine>  $lines
     * @param  array<int, OfferSnapshot>  $snapshots
     * @return array<int, list<OrderLine>> klucz = ID sprzedawcy, rosnąco
     */
    private function groupBySeller(array $lines, array $snapshots): array
    {
        $groups = [];

        foreach ($lines as $line) {
            $snapshot = $snapshots[$line->offerId];
            $groups[$snapshot->sellerId->value()][] = OrderLine::create(
                $snapshot->offerId,
                $snapshot->productName,
                $snapshot->price,
                LineQuantity::fromInt($line->quantity),
            );
        }

        ksort($groups);

        return $groups;
    }
}
