<?php

namespace SellerPortal\Domain\Offer\Event;

use DateTimeImmutable;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Offer\Quantity;
use SellerPortal\Domain\Offer\ReservationKey;
use SharedKernel\DomainEvent;

/**
 * Sztuki oferty zarezerwowane. DLACZEGO `available` i `listed` w zdarzeniu: rezerwacja ostatniej sztuki zdejmuje ofertę z półki, a odbiorcy (projekcja produktu) nie muszą tego liczyć sami.
 */
final readonly class OfferStockReserved implements DomainEvent
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
