<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use SellerPortal\Application\Port\Clock;
use SellerPortal\Application\Port\DomainEventDispatcher;
use SellerPortal\Application\Port\ProductCatalog;
use SellerPortal\Application\Port\TransactionManager;
use SellerPortal\Domain\Offer\OfferRepository;
use SellerPortal\Domain\Seller\SellerRepository;
use SellerPortal\Infrastructure\DbProductCatalog;
use SellerPortal\Infrastructure\InProcessDomainEventDispatcher;
use SellerPortal\Infrastructure\LaravelTransactionManager;
use SellerPortal\Infrastructure\Persistence\SqlOfferRepository;
use SellerPortal\Infrastructure\Persistence\SqlSellerRepository;
use SellerPortal\Infrastructure\SystemClock;

/**
 * Spina porty portalu sprzedawcy z implementacjami.
 *
 * DLACZEGO `bind`, a nie `singleton`: pod Octane singleton żyje między
 * żądaniami, a implementacje są bezstanowe i tanie. Wyjątek: dispatcher
 * trzyma zarejestrowanych słuchaczy, więc jest `scoped` (nowy per żądanie,
 * ten sam w obrębie jednego) — inaczej słuchacze gromadziliby się w workerze.
 */
class SellerPortalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SellerRepository::class, SqlSellerRepository::class);
        $this->app->bind(OfferRepository::class, SqlOfferRepository::class);
        $this->app->bind(Clock::class, SystemClock::class);
        $this->app->bind(TransactionManager::class, LaravelTransactionManager::class);
        $this->app->bind(ProductCatalog::class, DbProductCatalog::class);

        $this->app->scoped(InProcessDomainEventDispatcher::class);
        $this->app->bind(DomainEventDispatcher::class, InProcessDomainEventDispatcher::class);
    }
}
