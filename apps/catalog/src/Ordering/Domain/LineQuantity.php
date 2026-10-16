<?php

namespace Ordering\Domain;

use SharedKernel\InvalidValue;

/**
 * Liczba sztuk w pozycji zamówienia (1–10).
 *
 * DLACZEGO własny limit, a nie `Quantity` portalu (do 100 000): to reguła
 * sklepu dla kupującego (jedno zamówienie nie wykupuje całego magazynu),
 * nie reguła magazynu sprzedawcy.
 */
final readonly class LineQuantity
{
    public const int MAX = 10;

    private function __construct(private int $value) {}

    public static function fromInt(int $value): self
    {
        if ($value < 1 || $value > self::MAX) {
            throw new InvalidValue('Ilość w pozycji musi być od 1 do '.self::MAX.'.');
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
