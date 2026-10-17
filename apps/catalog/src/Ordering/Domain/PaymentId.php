<?php

namespace Ordering\Domain;

use SharedKernel\InvalidValue;

/**
 * Identyfikator wpłaty nadany przez bramkę (1–64 znaki). DLACZEGO nieprzezroczysty napis bez przycinania: to klucz idempotencji webhooka (H10), więc porównujemy go dokładnie tak, jak przyszedł.
 */
final readonly class PaymentId
{
    private const int MAX_LENGTH = 64;

    private function __construct(private string $value) {}

    public static function fromString(string $value): self
    {
        $length = mb_strlen($value);

        if ($length < 1 || $length > self::MAX_LENGTH) {
            throw new InvalidValue('Identyfikator musi mieć od 1 do '.self::MAX_LENGTH.' znaków.');
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
