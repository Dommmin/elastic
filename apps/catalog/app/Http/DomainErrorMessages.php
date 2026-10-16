<?php

namespace App\Http;

use SharedKernel\DomainException;

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
        'stock_below_reserved' => 'Nie możesz zejść poniżej liczby zarezerwowanych sztuk',
        'seller_already_registered' => 'To konto ma już profil sprzedawcy',
        'price_changed_since_cart' => 'Ceny w koszyku się zmieniły — sprawdź i złóż zamówienie ponownie',
        'offer_no_longer_available' => 'Część ofert w koszyku nie jest już dostępna',
        'cannot_buy_own_offer' => 'Nie możesz kupić własnej oferty',
        'invalid_order_transition' => 'Tej operacji nie można wykonać na zamówieniu w obecnym stanie',
        'cart_empty' => 'Koszyk jest pusty',
        // Jeden komunikat na dwie reguły osiągalne z koszyka (`InvalidOrderLines`:
        // ponad 20 ofert jednego sprzedawcy, oferty w dwóch walutach); pozostałe
        // (pusta lista, duplikat oferty) koszyk wyklucza wcześniej.
        'invalid_order_lines' => 'Tego koszyka nie da się złożyć — u jednego sprzedawcy zamówisz najwyżej 20 różnych ofert, wszystkie w jednej walucie',
    ];

    public static function for(DomainException $exception): string
    {
        $constant = $exception::class.'::CODE';
        $code = defined($constant) ? constant($constant) : null;

        return is_string($code) ? (self::MESSAGES[$code] ?? self::FALLBACK) : self::FALLBACK;
    }
}
