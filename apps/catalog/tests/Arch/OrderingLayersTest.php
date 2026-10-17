<?php

// Ordering zna Seller Portal tylko przez własne porty: z `SellerPortal\*`
// korzysta wyłącznie `Ordering\Infrastructure` (adaptery, ACL).
arch('domena zamówień nie zna frameworka, portalu ani innych warstw')
    ->expect('Ordering\Domain')
    ->not->toUse(['Illuminate', 'App', 'SellerPortal', 'Ordering\Application', 'Ordering\Infrastructure']);

arch('aplikacja zamówień nie zna frameworka, portalu ani infrastruktury')
    ->expect('Ordering\Application')
    ->not->toUse(['Illuminate', 'App', 'SellerPortal', 'Ordering\Infrastructure']);

// Termin płatności i karencja liczą się od `$now` z argumentu; `time()`
// w agregacie zepsułby deterministyczne testy wygaszania.
arch('domena zamówień nie woła funkcji zegara')
    ->expect('Ordering\Domain')
    ->not->toUse(['now', 'time', 'date', 'microtime']);

// Kierunek zależności jest jeden: zamówienia wołają portal, nigdy odwrotnie.
arch('portal sprzedawcy nie zna zamówień')
    ->expect('SellerPortal')
    ->not->toUse(['Ordering']);
