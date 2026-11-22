<?php

/*
 * Szablony reklamacji dla `seller360:simulate` (ETAP 13).
 *
 * `damage`: jedyne treści ze słowem z rodziny „uszkodz…” — dostają je WYŁĄCZNIE zasadzeni sprzedawcy
 * i bliskie pudła (poza pudłem „complaint”). Na tym opiera się precyzja = 1,0 w dowodzie z Task 12
 * (`simple_query_string "uszkodzon*"` na `complaints.recent_texts`).
 *
 * `other`: celowo inne słownictwo (opóźnienie, kontakt, rozmiar, faktura, zwrot). Żadne słowo nie
 * zaczyna się od „usz”, żeby polski stemmer (Stempel) nie sprowadził go do tego samego rdzenia.
 */
return [
    'damage' => [
        ['category' => 'transport', 'body' => 'Paczka przyszła uszkodzona, karton był zgnieciony.'],
        ['category' => 'produkt', 'body' => 'Produkt uszkodzony w transporcie, proszę o wymianę.'],
        ['category' => 'produkt', 'body' => 'Towar dotarł uszkodzony, obudowa pęknięta w dwóch miejscach.'],
        ['category' => 'transport', 'body' => 'Przesyłka uszkodzona, zawartość rozbita.'],
    ],
    'other' => [
        ['category' => 'dostawa', 'body' => 'Przesyłka opóźniona o tydzień bez żadnej informacji.'],
        ['category' => 'kontakt', 'body' => 'Brak kontaktu ze sprzedawcą, nikt nie odpowiada na wiadomości.'],
        ['category' => 'produkt', 'body' => 'Zły rozmiar, zamawiałem M, przyszło XL.'],
        ['category' => 'produkt', 'body' => 'Kolor inny niż na zdjęciu w ofercie.'],
        ['category' => 'faktura', 'body' => 'Nie otrzymałem faktury mimo prośby.'],
        ['category' => 'zwrot', 'body' => 'Zwrot pieniędzy trwa ponad miesiąc.'],
        ['category' => 'produkt', 'body' => 'W zestawie brakuje instrukcji i ładowarki.'],
        ['category' => 'dostawa', 'body' => 'Kurier zostawił paczkę pod złym adresem.'],
    ],
];
