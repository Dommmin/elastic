<?php

namespace SellerPortal\Application\Seller;

/**
 * Komenda: Użytkownik zgłasza się jako sprzedawca.
 * Same prymitywy, bo to kształt danych z UI; value objecty powstają dopiero w handlerze.
 */
final readonly class RegisterSeller
{
    public function __construct(
        public int $userId,
        public string $name,
        public string $city,
    ) {}
}
