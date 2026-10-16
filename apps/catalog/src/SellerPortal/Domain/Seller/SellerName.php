<?php

namespace SellerPortal\Domain\Seller;

use SharedKernel\InvalidValue;

/**
 * Nazwa sklepu widoczna dla kupujących.
 *
 * DLACZEGO VO: granice 3–120 znaków (po przycięciu, liczone w znakach, nie
 * bajtach) pilnuje jedno miejsce, więc żaden agregat nie przechowa nazwy
 * "  a ".
 */
final readonly class SellerName
{
    private const int MIN_LENGTH = 3;

    private const int MAX_LENGTH = 120;

    private function __construct(private string $value) {}

    public static function fromString(string $value): self
    {
        $trimmed = trim($value);
        $length = mb_strlen($trimmed);

        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw new InvalidValue('Nazwa sprzedawcy musi mieć od '.self::MIN_LENGTH.' do '.self::MAX_LENGTH.' znaków.');
        }

        return new self($trimmed);
    }

    public function value(): string
    {
        return $this->value;
    }
}
