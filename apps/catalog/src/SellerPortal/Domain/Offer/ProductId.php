<?php

namespace SellerPortal\Domain\Offer;

use SellerPortal\Domain\Shared\InvalidValue;

/**
 * Identyfikator produktu z katalogu. Oferta tylko się do niego ODWOŁUJE (po ID, nie po obiekcie): produkt należy do innego kontekstu, a agregaty łączymy identyfikatorami.
 */
final readonly class ProductId
{
    private function __construct(private int $value) {}

    public static function fromInt(int $value): self
    {
        if ($value <= 0) {
            throw new InvalidValue('Identyfikator musi być dodatni.');
        }

        return new self($value);
    }

    public function value(): int
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
