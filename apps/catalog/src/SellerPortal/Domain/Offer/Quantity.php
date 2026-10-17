<?php

namespace SellerPortal\Domain\Offer;

use SharedKernel\InvalidValue;

/**
 * Liczba rezerwowanych sztuk (1–100 000). DLACZEGO osobny typ, a nie `Stock`: zero na stanie jest znaczące (wyprzedana), a rezerwacja zera sztuk nie ma sensu.
 */
final readonly class Quantity
{
    private const int MIN = 1;

    private const int MAX = 100000;

    private function __construct(private int $value) {}

    public static function fromInt(int $value): self
    {
        if ($value < self::MIN || $value > self::MAX) {
            throw new InvalidValue('Liczba sztuk musi mieścić się w przedziale '.self::MIN.'–'.self::MAX.'.');
        }

        return new self($value);
    }

    public function value(): int
    {
        return $this->value;
    }
}
