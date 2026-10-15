<?php

arch('domena nie zna frameworka ani innych warstw')
    ->expect('SellerPortal\Domain')
    ->not->toUse(['Illuminate', 'App', 'SellerPortal\Application', 'SellerPortal\Infrastructure']);

arch('aplikacja nie zna frameworka ani infrastruktury')
    ->expect('SellerPortal\Application')
    ->not->toUse(['Illuminate', 'App', 'SellerPortal\Infrastructure']);
