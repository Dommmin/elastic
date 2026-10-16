<?php

namespace Ordering\Domain;

use SharedKernel\InvalidValue;

/**
 * Kupujący, czyli ID użytkownika. DLACZEGO własny typ Orderingu: kontekst zamówień nie importuje modelu kont z frameworka.
 */
final readonly class BuyerId
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
