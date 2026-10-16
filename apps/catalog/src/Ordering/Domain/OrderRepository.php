<?php

namespace Ordering\Domain;

use DateTimeImmutable;
use SharedKernel\ConcurrencyConflict;

/**
 * Port: jak domena widzi „kolekcję zamówień”. Implementacja (SQL) żyje w Infrastructure.
 */
interface OrderRepository
{
    public function nextIdentity(): OrderId;

    /**
     * @throws OrderNotFound
     */
    public function get(OrderId $id): Order;

    /**
     * @throws ConcurrencyConflict gdy ktoś zapisał zamówienie po jego odczycie
     */
    public function save(Order $order): void;

    /**
     * Zamówienia `Placed|AwaitingPayment` po terminie i karencji (`Order::isDueForExpiry`),
     * stronicowane po ID: sweep przetwarza paczki zamiast ładować wszystko naraz.
     *
     * @return list<OrderId>
     */
    public function idsDueForExpiry(DateTimeImmutable $now, int $afterId, int $limit): array;
}
