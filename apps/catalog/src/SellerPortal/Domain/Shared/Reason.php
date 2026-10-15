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
        // `trim()` nie zna twardej spacji (U+00A0) ani znaków niewidocznych:
        // bez tego "powód" złożony z samych takich znaków uchodziłby za niepusty.
        $trimmed = preg_replace('/^[\p{Z}\p{C}]+|[\p{Z}\p{C}]+$/u', '', $value);

        if ($trimmed === null) {
            throw new InvalidValue('Powód musi być poprawnym tekstem UTF-8.');
        }

        if ($trimmed === '') {
            throw new InvalidValue('Powód nie może być pusty.');
        }

        // Znaki sterujące (np. BEL, NUL) psują logi i widoki; \n, \r, \t zostają,
        // bo powód pochodzi z textarea i bywa wieloliniowy.
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $trimmed) === 1) {
            throw new InvalidValue('Powód zawiera niedozwolone znaki sterujące.');
        }

        if (mb_strlen($trimmed, 'UTF-8') > self::MAX_LENGTH) {
            throw new InvalidValue('Powód może mieć najwyżej '.self::MAX_LENGTH.' znaków.');
        }

        return new self($trimmed);
    }

    public function value(): string
    {
        return $this->value;
    }
}
