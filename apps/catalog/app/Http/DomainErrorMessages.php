<?php

namespace App\Http;

use SellerPortal\Domain\Shared\DomainException;

/**
 * Jedyne miejsce, które tłumaczy kody reguł domeny na polskie komunikaty.
 *
 * DLACZEGO po `CODE`, a nie po treści wyjątku: domena zwraca stabilny
 * identyfikator, tekst dla człowieka należy do UI i może się zmieniać bez
 * dotykania domeny. Nieznany kod dostaje ogólny komunikat zamiast
 * wewnętrznej treści wyjątku.
 */
final class DomainErrorMessages
{
    public const string FALLBACK = 'Operacja niedozwolona w obecnym stanie';

    private const array MESSAGES = [
        'price_change_limit' => 'Wykorzystano limit 5 zmian ceny na dobę',
        'no_pending_price_change' => 'Ta oferta nie ma już zgłoszonej zmiany ceny',
        'price_change_pending' => 'Ta oferta czeka na decyzję moderatora — anuluj zgłoszenie, aby zmienić cenę',
        'pending_price_changed' => 'Sprzedawca zmienił zgłoszoną cenę — odśwież kolejkę',
        'concurrency_conflict' => 'Ktoś w międzyczasie zmienił tę ofertę — odśwież stronę',
        'live_offer_exists' => 'Masz już aktywną ofertę na ten produkt',
        'offer_without_stock' => 'Nie można opublikować oferty bez towaru',
        // Bez „Twoje”: ten sam kod widzi sprzedawca (własne konto) i moderator
        // (przywrócenie oferty zablokowanego sprzedawcy, `Offer::reinstate`).
        'seller_cannot_sell' => 'Konto sprzedawcy nie jest aktywne',
        'seller_already_registered' => 'To konto ma już profil sprzedawcy',
    ];

    public static function for(DomainException $exception): string
    {
        $constant = $exception::class.'::CODE';
        $code = defined($constant) ? constant($constant) : null;

        return is_string($code) ? (self::MESSAGES[$code] ?? self::FALLBACK) : self::FALLBACK;
    }
}
