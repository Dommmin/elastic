<?php

namespace SellerPortal\Application;

use SellerPortal\Domain\Offer\Offer;
use SellerPortal\Domain\Shared\ConcurrencyConflict;

/**
 * Porównanie wersji, którą człowiek widział na ekranie, z wersją wczytaną teraz.
 *
 * DLACZEGO osobno od blokady w repozytorium: `SqlOfferRepository::save`
 * łapie tylko zapisy nakładające się w czasie (odczyt → zapis w obu
 * żądaniach w tych samych milisekundach). Dwie karty otwarte od kwadransa
 * nie nakładają się wcale — druga wczytuje świeży agregat i bez tego
 * sprawdzenia nadpisałaby zmianę, której człowiek nie widział. Formularz
 * odsyła więc `version` z wyrenderowanej strony, a handler porównuje ją tuż
 * po wczytaniu.
 *
 * DLACZEGO warstwa aplikacji, a nie agregat: „jaką wersję widział
 * użytkownik” to fakt o interfejsie i komendzie, nie reguła biznesowa
 * oferty. Polityki (joby blokady/odblokowania) tego nie używają — działają
 * na najświeższym stanie, nikt im niczego nie wyświetlał.
 */
final class ExpectedVersion
{
    /**
     * @throws ConcurrencyConflict
     */
    public static function check(Offer $offer, int $expectedVersion): void
    {
        if ($offer->version() !== $expectedVersion) {
            throw new ConcurrencyConflict;
        }
    }
}
