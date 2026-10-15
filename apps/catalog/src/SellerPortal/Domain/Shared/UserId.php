<?php

namespace SellerPortal\Domain\Shared;

/**
 * Identyfikator użytkownika (konto sprzedawcy). Domena zna tylko numer, nie model Eloquenta — dzięki temu nie ciągnie za sobą frameworka.
 */
final readonly class UserId
{
    private function __construct(private int $value) {}

    public static function fromInt(int $value): self
    {
        if ($value <= 0) {
            throw new InvalidValue('Identyfikator musi być dodatni.');
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
