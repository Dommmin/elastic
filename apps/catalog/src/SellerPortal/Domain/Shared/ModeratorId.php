<?php

namespace SellerPortal\Domain\Shared;

use SharedKernel\InvalidValue;

/**
 * Identyfikator moderatora wykonującego decyzję. Osobny typ od UserId, żeby kompilator (i PHPStan) nie pozwolił pomylić sprzedawcy z moderatorem w sygnaturze.
 */
final readonly class ModeratorId
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
