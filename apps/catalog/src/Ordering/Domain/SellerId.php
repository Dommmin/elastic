<?php

namespace Ordering\Domain;

use SharedKernel\InvalidValue;

/**
 * Sprzedawca widziany z zamówień. DLACZEGO nie `SellerPortal\Domain\Seller\SellerId`: Ordering nie zna modelu portalu (inny kontekst); ta sama liczba, osobny typ, tłumaczy ją adapter w Infrastructure.
 */
final readonly class SellerId
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
