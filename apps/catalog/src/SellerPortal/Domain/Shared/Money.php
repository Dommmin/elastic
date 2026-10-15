<?php

namespace SellerPortal\Domain\Shared;

/**
 * Kwota w groszach + waluta (value object).
 *
 * DLACZEGO `int` w groszach, a nie `float`: 0.1 + 0.2 != 0.3. Arytmetyka
 * całkowitoliczbowa daje dokładne porównania progów (np. "więcej niż 30%"
 * rozstrzyga się co do grosza, bez zaokrągleń).
 *
 * DLACZEGO prywatny konstruktor + fabryka: nie da się utworzyć kwoty <= 0,
 * więc każdy obiekt `Money` w systemie jest prawidłowy z definicji i żaden
 * kod dalej nie musi tego sprawdzać.
 */
final readonly class Money
{
    private function __construct(
        private int $cents,
        private Currency $currency,
    ) {}

    public static function fromCents(int $cents, Currency $currency = Currency::PLN): self
    {
        if ($cents <= 0) {
            throw new InvalidValue('Kwota musi być większa od zera.');
        }

        return new self($cents, $currency);
    }

    public function cents(): int
    {
        return $this->cents;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents && $this->currency === $other->currency;
    }

    /**
     * Czy `$other` różni się od tej kwoty o ściśle więcej niż `$percent`
     * procent.
     *
     * PUNKT ODNIESIENIA to `$this` (stara, obecna cena), nie `$other`:
     * 10000 → 13001 to `true` (+30,01%), ale 13001 → 10000 to `false`
     * (−23,08%). Dlatego wołaj zawsze jako `$obecna->differs...($proponowana, …)`.
     * Dokładnie 30% to jeszcze NIE "więcej niż 30%". Liczymy na int:
     * |inna − ta| × 100 > ta × procent.
     *
     * @throws CurrencyMismatch gdy waluty się różnią
     * @throws InvalidValue gdy `$percent` poza 0..100
     */
    public function differsByMoreThanPercent(self $other, int $percent): bool
    {
        if ($percent < 0 || $percent > 100) {
            throw new InvalidValue('Procent musi być w zakresie 0–100.');
        }

        if ($this->currency !== $other->currency) {
            throw CurrencyMismatch::between($this->currency, $other->currency);
        }

        return abs($other->cents - $this->cents) * 100 > $this->cents * $percent;
    }
}
