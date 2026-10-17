<?php

namespace SellerPortal\Domain\Offer\Event;

use DateTimeImmutable;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Offer\Stock;
use SharedKernel\DomainEvent;

/**
 * Zmienił się stan magazynowy (bez przejścia przez zero na opublikowanej ofercie).
 */
final readonly class OfferStockChanged implements DomainEvent
{
    public function __construct(
        public OfferId $offerId,
        public Stock $old,
        public Stock $new,
        public DateTimeImmutable $occurredAt,
    ) {}

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
