<?php

namespace App\Providers;

use Billing\Application\Port\Clock;
use Billing\Domain\BillingAccountRepository;
use Billing\Infrastructure\SqlBillingAccountRepository;
use Billing\Infrastructure\SystemClock;
use Illuminate\Support\ServiceProvider;

/**
 * Spina porty kontekstu Billing z implementacjami.
 *
 * DLACZEGO `bind`, a nie `singleton`: pod Octane singleton żyje między
 * żądaniami, a implementacje są bezstanowe i tanie.
 */
class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(BillingAccountRepository::class, SqlBillingAccountRepository::class);
        $this->app->bind(Clock::class, SystemClock::class);
    }
}
