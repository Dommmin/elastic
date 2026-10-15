<?php

namespace SellerPortal\Application\Moderation;

/**
 * Komenda: Moderator zawiesza ofertę z notatką.
 * Same prymitywy, bo to kształt danych z UI; value objecty powstają dopiero w handlerze.
 */
final readonly class SuspendOffer
{
    public function __construct(
        public int $moderatorId,
        public int $offerId,
        public string $note,
    ) {}
}
