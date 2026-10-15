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
        /** Zgłoszona cena, którą moderator widział w kolejce (zob. `PendingPriceChangeMismatch`). */
        public int $expectedPriceCents,
        /** Wersja oferty z wiersza kolejki (zob. `ExpectedVersion`). */
        public int $expectedVersion,
    ) {}
}
