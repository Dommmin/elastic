<?php

namespace Ordering\Domain;

use SharedKernel\DomainException;

/**
 * Pozycje łamią niezmiennik zamówienia: 1–20 pozycji, każda oferta raz,
 * jedna waluta; albo saga wskazała ofertę, której w zamówieniu nie ma.
 *
 * DLACZEGO do UI to nie dociera jako osobny komunikat: koszyk już pilnuje
 * tych reguł (sumuje ilości tej samej oferty, grupuje po sprzedawcy). Ten
 * wyjątek to ostatnia linia obrony agregatu przed błędem w warstwie wyżej.
 */
final class InvalidOrderLines extends DomainException
{
    public const string CODE = 'invalid_order_lines';

    public static function empty(): self
    {
        return new self('Zamówienie musi mieć co najmniej jedną pozycję.');
    }

    public static function tooMany(int $max): self
    {
        return new self("Zamówienie może mieć najwyżej {$max} pozycji.");
    }

    public static function duplicateOffer(OfferId $offerId): self
    {
        return new self("Oferta {$offerId->value()} występuje w zamówieniu więcej niż raz.");
    }

    public static function mixedCurrencies(): self
    {
        return new self('Wszystkie pozycje zamówienia muszą być w jednej walucie.');
    }

    public static function unknownOffer(OfferId $offerId): self
    {
        return new self("Zamówienie nie ma pozycji z ofertą {$offerId->value()}.");
    }
}
