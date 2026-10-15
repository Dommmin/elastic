<?php

namespace SellerPortal\Domain\Shared;

/**
 * Uzasadnienie decyzji człowieka (odrzucenie zmiany ceny, zawieszenie oferty).
 *
 * DLACZEGO VO, a nie goły string: reguły "niepusty po przycięciu, ≤ 500
 * znaków" żyją w jednym miejscu, a pusty powód nie ma jak trafić do
 * historii decyzji moderatora. Długość liczymy w znakach (mb_), nie w bajtach.
 */
final readonly class Reason
{
    private const int MAX_LENGTH = 500;

    private function __construct(private string $value) {}

    public static function fromString(string $value): self
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new InvalidValue('Powód nie może być pusty.');
        }

        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw new InvalidValue('Powód może mieć najwyżej '.self::MAX_LENGTH.' znaków.');
        }

        return new self($trimmed);
    }

    public function value(): string
    {
        return $this->value;
    }
}
