<?php

namespace SellerPortal\Application\Moderation;

/**
 * Komenda: Moderator przywraca zawieszoną ofertę.
 * Same prymitywy, bo to kształt danych z UI; value objecty powstają dopiero w handlerze.
 */
final readonly class ReinstateOffer
{
    public function __construct(
        public int $moderatorId,
        public int $offerId,
        /** Wersja oferty, którą człowiek widział, gdy wysyłał formularz (zob. `ExpectedVersion`). */
        public int $expectedVersion,
    ) {}
}
