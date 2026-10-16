<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Ordering\Application\Port\Clock;
use Ordering\Application\Port\DomainEventDispatcher;
use Ordering\Application\Port\OfferSnapshots;
use Ordering\Application\Port\ProcessJobQueue;
use Ordering\Application\Port\SellerAccounts;
use Ordering\Application\Port\StockReservations;
use Ordering\Application\Port\TransactionManager;
use Ordering\Application\Process\OrderProcessManager;
use Ordering\Domain\Event\OrderCancelled;
use Ordering\Domain\Event\OrderExpired;
use Ordering\Domain\Event\OrderPaid;
use Ordering\Domain\Event\OrderPlaced;
use Ordering\Domain\Event\OrderRejected;
use Ordering\Domain\Event\RefundRequested;
use Ordering\Domain\OrderRepository;
use Ordering\Infrastructure\DbOfferSnapshots;
use Ordering\Infrastructure\DbSellerAccounts;
use Ordering\Infrastructure\InProcessDomainEventDispatcher;
use Ordering\Infrastructure\LaravelProcessJobQueue;
use Ordering\Infrastructure\LaravelTransactionManager;
use Ordering\Infrastructure\Persistence\SqlOrderRepository;
use Ordering\Infrastructure\SellerPortalStockReservations;
use Ordering\Infrastructure\SystemClock;

/**
 * Spina porty kontekstu zamówień z implementacjami.
 *
 * DLACZEGO `bind`, a nie `singleton`: pod Octane singleton żyje między
 * żądaniami, a implementacje są bezstanowe i tanie. Wyjątek: dispatcher
 * trzyma słuchaczy, więc jest `scoped` (wzorzec `SellerPortalServiceProvider`).
 *
 * Porty `BuyerCart` i `PaymentGateway` dostają adaptery w późniejszych
 * zadaniach (koszyk, bramka płatności), tu ich jeszcze nie ma.
 */
class OrderingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrderRepository::class, SqlOrderRepository::class);
        $this->app->bind(Clock::class, SystemClock::class);
        $this->app->bind(TransactionManager::class, LaravelTransactionManager::class);
        $this->app->bind(StockReservations::class, SellerPortalStockReservations::class);
        $this->app->bind(OfferSnapshots::class, DbOfferSnapshots::class);
        $this->app->bind(SellerAccounts::class, DbSellerAccounts::class);
        $this->app->bind(ProcessJobQueue::class, LaravelProcessJobQueue::class);

        // Słuchacze rejestrowani W fabryce scoped, a nie w boot(): po flushu
        // scoped instancji (Octane) fabryka biegnie od nowa, więc każda
        // świeża instancja dispatchera ma słuchaczy sagi. Mapa zdarzeń jest
        // jawna i identyczna jak `OrderingWorld::routeToProcessManager` w testach.
        $this->app->scoped(InProcessDomainEventDispatcher::class, function ($app) {
            $dispatcher = new InProcessDomainEventDispatcher;
            $manager = $app->make(OrderProcessManager::class);

            $dispatcher->listen(OrderPlaced::class, $manager->onPlaced(...));
            $dispatcher->listen(OrderRejected::class, $manager->onRejected(...));
            $dispatcher->listen(OrderExpired::class, $manager->onExpired(...));
            $dispatcher->listen(OrderCancelled::class, $manager->onCancelled(...));
            $dispatcher->listen(OrderPaid::class, $manager->onPaid(...));
            $dispatcher->listen(RefundRequested::class, $manager->onRefundRequested(...));

            return $dispatcher;
        });
        $this->app->bind(DomainEventDispatcher::class, InProcessDomainEventDispatcher::class);
    }
}
