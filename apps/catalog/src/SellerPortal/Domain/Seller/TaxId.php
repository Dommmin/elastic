<?php

namespace SellerPortal\Domain\Seller;

use SharedKernel\InvalidValue;

/**
 * NIP sprzedawcy: 10 cyfr z poprawną sumą kontrolną.
 *
 * DLACZEGO VO z walidacją sumy: literówka w NIP-ie wychodzi na jaw przy
 * rejestracji, a nie dopiero przy próbie zapytania rejestru. Myślniki i
 * spacje to tylko formatowanie z formularza, więc normalizujemy je do
 * 10 cyfr — w bazie i zdarzeniach żyje wyłącznie postać znormalizowana.
 */
final readonly class TaxId
{
    /** Wagi kolejnych 9 cyfr; suma ważona modulo 11 musi dać dziesiątą cyfrę. */
    private const array WEIGHTS = [6, 5, 7, 2, 3, 4, 5, 6, 7];

    private function __construct(private string $value) {}

    public static function fromString(string $nip): self
    {
        $digits = str_replace(['-', ' '], '', $nip);

        if (preg_match('/^\d{10}$/', $digits) !== 1) {
            throw new InvalidValue('NIP musi mieć 10 cyfr.');
        }

        if (self::checksum($digits) !== (int) $digits[9]) {
            throw new InvalidValue('NIP ma niepoprawną sumę kontrolną.');
        }

        return new self($digits);
    }

    /**
     * Cyfra kontrolna dla pierwszych 9 cyfr; 10 oznacza, że takiego NIP-u nie ma.
     */
    public static function checksum(string $digits): int
    {
        $sum = 0;
        foreach (self::WEIGHTS as $i => $weight) {
            $sum += $weight * (int) $digits[$i];
        }

        return $sum % 11;
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
