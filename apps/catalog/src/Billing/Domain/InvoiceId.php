<?php

namespace Billing\Domain;

use SharedKernel\InvalidValue;

/**
 * Tożsamość faktury: ULID (26 znaków) nadawany przy wystawieniu, bez sekwencji w bazie.
 */
final readonly class InvoiceId
{
    private function __construct(private string $value) {}

    public static function fromString(string $value): self
    {
        if (trim($value) === '') {
            throw new InvalidValue('Identyfikator faktury nie może być pusty.');
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
