<?php

arch('domena nie zna frameworka ani innych warstw')
    ->expect('SellerPortal\Domain')
    ->not->toUse(['Illuminate', 'App', 'SellerPortal\Application', 'SellerPortal\Infrastructure']);

arch('aplikacja nie zna frameworka ani infrastruktury')
    ->expect('SellerPortal\Application')
    ->not->toUse(['Illuminate', 'App', 'SellerPortal\Infrastructure']);

// Domena nie czyta zegara sama: każda zmiana stanu dostaje `DateTimeImmutable $now`
// (Global Constraints). Bez tej reguły `time()` w agregacie przeszedłby CI
// i zepsuł testy zależne od czasu dopiero przy przeglądzie.
arch('domena nie woła funkcji zegara')
    ->expect('SellerPortal\Domain')
    ->not->toUse(['now', 'time', 'date', 'microtime']);
