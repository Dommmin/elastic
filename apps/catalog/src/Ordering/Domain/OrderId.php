<?php

namespace Ordering\Domain;

use SharedKernel\InvalidValue;

/**
 * Tożsamość agregatu Order. Osobny typ, żeby nie pomylić go w sygnaturze z BuyerId czy OfferId.
 */
final readonly class OrderId
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
