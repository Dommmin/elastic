<?php

namespace SellerPortal\Domain\Offer;

use SharedKernel\InvalidValue;

/**
 * Liczba sztuk na stanie (0–100 000). DLACZEGO VO: zero jest wartością dozwoloną i znaczącą (oferta wyprzedana), a ujemny stan albo absurdalnie duża liczba nie ma jak wejść do agregatu.
 */
final readonly class Stock
{
    private const int MIN = 0;

    private const int MAX = 100000;

    private function __construct(private int $value) {}

    public static function fromInt(int $value): self
    {
        if ($value < self::MIN || $value > self::MAX) {
            throw new InvalidValue('Stan magazynowy musi mieścić się w przedziale '.self::MIN.'–'.self::MAX.'.');
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
