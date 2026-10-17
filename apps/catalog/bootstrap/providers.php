<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\OrderingServiceProvider;
use App\Providers\SellerPortalServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    SellerPortalServiceProvider::class,
    OrderingServiceProvider::class,
];
