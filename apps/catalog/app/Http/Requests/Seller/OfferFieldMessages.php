<?php

namespace App\Http\Requests\Seller;

/**
 * Polskie komunikaty pól oferty wspólne dla kilku formularzy (locale
 * aplikacji to `en` i nie ma katalogu `lang/`).
 */
final class OfferFieldMessages
{
    public const array STOCK = [
        'stock.required' => 'Podaj stan magazynowy.',
        'stock.integer' => 'Stan magazynowy musi być liczbą całkowitą.',
        'stock.min' => 'Stan magazynowy nie może być ujemny.',
        'stock.max' => 'Stan magazynowy może wynosić najwyżej 100 000.',
    ];

    public const array DETAILS = [
        'condition.required' => 'Wybierz stan egzemplarza.',
        'condition.enum' => 'Wybierz stan egzemplarza: nowy albo używany.',
        'shipping_days.required' => 'Podaj czas wysyłki.',
        'shipping_days.integer' => 'Czas wysyłki musi być liczbą dni.',
        'shipping_days.min' => 'Czas wysyłki to co najmniej 1 dzień.',
        'shipping_days.max' => 'Czas wysyłki to najwyżej 30 dni.',
    ];
}
