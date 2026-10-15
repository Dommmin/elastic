<?php

namespace SellerPortal\Domain\Seller;

use SellerPortal\Domain\Shared\InvalidValue;

/**
 * Miasto sprzedawcy. Celowo tylko tekst: słownika miejscowości nie
 * prowadzimy, a VO pilnuje jedynie, że wartość nie jest pusta ani absurdalnie długa.
 */
final readonly class City
{
    private const int MAX_LENGTH = 120;

    private function __construct(private string $value) {}

    public static function fromString(string $value): self
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new InvalidValue('Miasto nie może być puste.');
        }

        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw new InvalidValue('Miasto może mieć najwyżej '.self::MAX_LENGTH.' znaków.');
        }

        return new self($trimmed);
    }

    public function value(): string
    {
        return $this->value;
    }
}
