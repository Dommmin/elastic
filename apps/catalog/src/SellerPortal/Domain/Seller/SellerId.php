<?php

namespace SellerPortal\Domain\Seller;

use SellerPortal\Domain\Shared\InvalidValue;

/**
 * Tożsamość agregatu Seller. Osobny typ od UserId: konto logowania i profil
 * sprzedawcy to dwie różne rzeczy, a osobne typy nie pozwolą ich pomylić w sygnaturze.
 */
final readonly class SellerId
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
