<?php

namespace SharedKernel;

/**
 * Próba porównania lub działania na kwotach w różnych walutach.
 *
 * DLACZEGO wyjątek, a nie przeliczenie po kursie: kurs to wiedza spoza tej
 * domeny. Cichy wynik porównania 100 PLN z 100 EUR byłby groźniejszy niż błąd.
 */
final class CurrencyMismatch extends DomainException
{
    public const string CODE = 'currency_mismatch';

    public static function between(Currency $left, Currency $right): self
    {
        return new self("Nie można porównać walut {$left->value} i {$right->value}.");
    }
}
