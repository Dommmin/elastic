<?php

namespace Ordering\Domain;

use SharedKernel\InvalidValue;

/**
 * Identyfikator zwrotu (1–64 znaki), zarazem klucz idempotencji w bramce. Nadaje go agregat deterministycznie (`refund-{orderId}-{n}`), żeby ponowiony job nie zlecił drugiego zwrotu.
 */
final readonly class RefundId
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
