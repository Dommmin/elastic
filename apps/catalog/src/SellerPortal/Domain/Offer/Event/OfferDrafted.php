<?php

namespace SellerPortal\Domain\Offer\Event;

use DateTimeImmutable;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Offer\ProductId;
use SellerPortal\Domain\Seller\SellerId;
use SharedKernel\DomainEvent;

/**
 * Sprzedawca utworzył szkic oferty.
 */
final readonly class OfferDrafted implements DomainEvent
{
    public function __construct(
        public OfferId $offerId,
        public SellerId $sellerId,
        public ProductId $productId,
        public DateTimeImmutable $occurredAt,
    ) {}

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
