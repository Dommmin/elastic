<?php

namespace Ordering\Domain;

use SharedKernel\InvalidValue;

/**
 * Oferta widziana z zamówień: tylko identyfikator, który adapter rezerwacji tłumaczy na ofertę portalu. Własny typ z tego samego powodu co `SellerId`.
 */
final readonly class OfferId
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
