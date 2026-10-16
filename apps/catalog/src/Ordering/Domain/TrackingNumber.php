<?php

namespace Ordering\Domain;

use SharedKernel\InvalidValue;

/**
 * Numer przesyłki podany przez sprzedawcę (3–64 znaki po przycięciu). DLACZEGO przycinamy: numer wklejony ze spacjami to wciąż ten sam numer, a numer z samych spacji to brak numeru.
 */
final readonly class TrackingNumber
{
    private const int MIN_LENGTH = 3;

    private const int MAX_LENGTH = 64;

    private function __construct(private string $value) {}

    public static function fromString(string $value): self
    {
        $trimmed = TextTrim::trim($value);
        $length = mb_strlen($trimmed, 'UTF-8');

        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw new InvalidValue('Numer przesyłki musi mieć od '.self::MIN_LENGTH.' do '.self::MAX_LENGTH.' znaków.');
        }

        return new self($trimmed);
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
