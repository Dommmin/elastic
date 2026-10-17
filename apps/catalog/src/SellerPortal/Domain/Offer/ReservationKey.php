<?php

namespace SellerPortal\Domain\Offer;

use SharedKernel\InvalidValue;

/**
 * Klucz rezerwacji (1–64 znaki) nadany przez tego, kto rezerwuje.
 *
 * DLACZEGO nieprzezroczysty napis, a nie ID zamówienia: portal nie wie, kto
 * i po co rezerwuje (Ordering podaje `order-{id}`). Klucz daje idempotencję:
 * ponowiony job z tym samym kluczem nie zarezerwuje drugi raz.
 */
final readonly class ReservationKey
{
    private const int MAX_LENGTH = 64;

    private function __construct(private string $value) {}

    public static function fromString(string $value): self
    {
        $length = mb_strlen($value);

        if ($length < 1 || $length > self::MAX_LENGTH) {
            throw new InvalidValue('Klucz rezerwacji musi mieć od 1 do '.self::MAX_LENGTH.' znaków.');
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
