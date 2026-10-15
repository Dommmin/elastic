<?php

namespace SellerPortal\Application\Moderation;

/**
 * Komenda: Moderator akceptuje oczekującą zmianę ceny.
 * Same prymitywy, bo to kształt danych z UI; value objecty powstają dopiero w handlerze.
 */
final readonly class ApprovePriceChange
{
    public function __construct(
        public int $moderatorId,
        public int $offerId,
    ) {}
}
