<?php

namespace SellerPortal\Domain\Seller\Event;

use DateTimeImmutable;
use SellerPortal\Domain\Seller\City;
use SellerPortal\Domain\Seller\SellerId;
use SharedKernel\DomainEvent;

/**
 * Sprzedawca zmienił miasto. Zdarzenie niesie nową wartość (nie różnicę), więc odbiorca
 * odtworzy stan bez znajomości poprzedniego.
 */
final readonly class SellerRelocated implements DomainEvent
{
    public function __construct(
        public SellerId $sellerId,
        public City $city,
        public DateTimeImmutable $occurredAt,
    ) {}

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
