<?php

namespace SellerPortal\Domain\Offer;

use SellerPortal\Domain\Shared\DomainException;

/**
 * Przekroczony limit zmian ceny w kroczącym oknie 24 h.
 *
 * DLACZEGO limit: bez niego sprzedawca mógłby wielokrotnie zmieniać cenę tuż przed kliknięciem kupującego (bait-and-switch).
 */
final class PriceChangeLimitExceeded extends DomainException
{
    public const string CODE = 'price_change_limit';

    public function __construct(string $message = 'Przekroczono limit zmian ceny w ciągu doby.')
    {
        parent::__construct($message);
    }
}
