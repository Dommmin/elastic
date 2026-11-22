<?php

namespace SellerPortal\Domain\Seller\Event;

use DateTimeImmutable;
use SellerPortal\Domain\Seller\SellerId;
use SellerPortal\Domain\Seller\SellerName;
use SharedKernel\DomainEvent;

/**
 * Sprzedawca zmienił nazwę sklepu. Zdarzenie niesie nową wartość (nie różnicę), więc odbiorca
 * odtworzy stan bez znajomości poprzedniego.
 */
final readonly class SellerRenamed implements DomainEvent
{
    public function __construct(
        public SellerId $sellerId,
        public SellerName $name,
        public DateTimeImmutable $occurredAt,
    ) {}

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
