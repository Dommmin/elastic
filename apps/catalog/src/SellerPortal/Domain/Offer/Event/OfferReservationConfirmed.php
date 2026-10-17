<?php

namespace SellerPortal\Domain\Offer\Event;

use DateTimeImmutable;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Offer\Quantity;
use SellerPortal\Domain\Offer\ReservationKey;
use SellerPortal\Domain\Offer\Stock;
use SharedKernel\DomainEvent;

/**
 * Rezerwacja zamieniona w sprzedaż: stan spadł o `quantity`. DLACZEGO bez `available`: nie zmienia się (sztuki były już odjęte rezerwacją), zmienia się tylko `stock`.
 */
final readonly class OfferReservationConfirmed implements DomainEvent
{
    public function __construct(
        public OfferId $offerId,
        public ReservationKey $key,
        public Quantity $quantity,
        public Stock $stock,
        public DateTimeImmutable $occurredAt,
    ) {}

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
