<?php

namespace SellerPortal\Application\Offer;

/**
 * Komenda: Sprzedawca zmienia cenę oferty (poniżej progu od razu, powyżej do akceptacji).
 * Same prymitywy, bo to kształt danych z UI; value objecty powstają dopiero w handlerze.
 */
final readonly class ChangeOfferPrice
{
    public function __construct(
        public int $userId,
        public int $offerId,
        public int $priceCents,
        /** Wersja oferty, którą człowiek widział, gdy wysyłał formularz (zob. `ExpectedVersion`). */
        public int $expectedVersion,
    ) {}
}
