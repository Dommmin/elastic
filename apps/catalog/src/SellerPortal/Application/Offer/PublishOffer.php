<?php

namespace SellerPortal\Application\Offer;

/**
 * Komenda: Sprzedawca publikuje swój szkic.
 * Same prymitywy, bo to kształt danych z UI; value objecty powstają dopiero w handlerze.
 */
final readonly class PublishOffer
{
    public function __construct(
        public int $userId,
        public int $offerId,
        /** Wersja oferty, którą człowiek widział, gdy wysyłał formularz (zob. `ExpectedVersion`). */
        public int $expectedVersion,
    ) {}
}
