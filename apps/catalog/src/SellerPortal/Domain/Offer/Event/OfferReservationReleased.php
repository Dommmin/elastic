<?php

namespace SellerPortal\Domain\Offer\Event;

use DateTimeImmutable;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Offer\Quantity;
use SellerPortal\Domain\Offer\ReservationKey;
use SharedKernel\DomainEvent;

/**
 * Rezerwacja zwolniona, sztuki znów dostępne. `listed` mówi, czy oferta wróciła na półkę (po wycofaniu nie wraca).
 */
final readonly class OfferReservationReleased implements DomainEvent
{
    public function __construct(
        public OfferId $offerId,
        public ReservationKey $key,
        public Quantity $quantity,
        public int $available,
        public bool $listed,
        public DateTimeImmutable $occurredAt,
    ) {}

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
