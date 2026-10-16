<?php

// Shared Kernel to wspólny rdzeń dwóch kontekstów (portal, zamówienia):
// gdyby znał któryś z nich, przestałby być wspólny.
arch('shared kernel nie zna nikogo')
    ->expect('SharedKernel')
    ->not->toUse(['Illuminate', 'App', 'SellerPortal', 'Ordering']);

arch('shared kernel nie woła funkcji zegara')
    ->expect('SharedKernel')
    ->not->toUse(['now', 'time', 'date', 'microtime']);
