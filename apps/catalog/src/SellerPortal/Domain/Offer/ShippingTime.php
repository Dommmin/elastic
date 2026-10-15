<?php

namespace SellerPortal\Domain\Offer;

use SellerPortal\Domain\Shared\InvalidValue;

/**
 * Czas wysyłki w dniach (1–30). DLACZEGO VO: zakres to reguła biznesowa, nie walidacja formularza — obowiązuje też, gdy ofertę zmienia kod, a nie człowiek.
 */
final readonly class ShippingTime
{
    private const int MIN = 1;

    private const int MAX = 30;

    private function __construct(private int $value) {}

    public static function fromInt(int $value): self
    {
        if ($value < self::MIN || $value > self::MAX) {
            throw new InvalidValue('Czas wysyłki (dni) musi mieścić się w przedziale '.self::MIN.'–'.self::MAX.'.');
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
