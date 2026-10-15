<?php

namespace SellerPortal\Application\Moderation;

/**
 * Komenda: Moderator odrzuca oczekującą zmianę ceny z podaniem powodu.
 * Same prymitywy, bo to kształt danych z UI; value objecty powstają dopiero w handlerze.
 */
final readonly class RejectPriceChange
{
    public function __construct(
        public int $moderatorId,
        public int $offerId,
        public string $reason,
        /** Zgłoszona cena, którą moderator widział w kolejce (zob. `PendingPriceChangeMismatch`). */
        public int $expectedPriceCents,
        /** Wersja oferty z wiersza kolejki (zob. `ExpectedVersion`). */
        public int $expectedVersion,
    ) {}
}
