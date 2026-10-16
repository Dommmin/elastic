<?php

namespace SellerPortal\Domain\Offer;

use DateTimeImmutable;
use SharedKernel\Money;

/**
 * Zgłoszona, jeszcze niezaakceptowana zmiana ceny (część agregatu Offer).
 *
 * DLACZEGO to VO wewnątrz Offer, a nie osobny agregat: zmiana ceny nie
 * istnieje bez oferty i zawsze jest rozpatrywana razem z jej stanem.
 * Zachowanie (zgłaszanie, akceptacja) dochodzi osobno.
 */
final readonly class PendingPriceChange
{
    public function __construct(
        private Money $price,
        private DateTimeImmutable $requestedAt,
    ) {}

    public function price(): Money
    {
        return $this->price;
    }

    public function requestedAt(): DateTimeImmutable
    {
        return $this->requestedAt;
    }
}
