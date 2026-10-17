<?php

namespace Ordering\Domain;

use SharedKernel\InvalidValue;

/**
 * Uzasadnienie anulowania przez sprzedawcę (niepuste po przycięciu, ≤ 500 znaków).
 *
 * DLACZEGO własny VO, a nie `Reason` portalu: Ordering nie importuje
 * `SellerPortal\*`. Reguły są celowo te same, bo to ten sam rodzaj tekstu
 * (decyzja człowieka z textarea), ale każdy kontekst może je zmienić osobno.
 */
final readonly class Reason
{
    private const int MAX_LENGTH = 500;

    private function __construct(private string $value) {}

    public static function fromString(string $value): self
    {
        $trimmed = TextTrim::trim($value);

        if ($trimmed === '') {
            throw new InvalidValue('Powód nie może być pusty.');
        }

        // Znaki sterujące psują logi i widoki; \n, \r, \t zostają (tekst wieloliniowy).
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

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
