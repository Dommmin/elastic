<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Jeden klient ES na cały request lifecycle — używany przez
        // HealthController (readiness) i przyszły ProductSearchService
        // (ETAP 7, decyzja D-09: logika wyszukiwania w klasie serwisowej).
        // `catalog` łączy się na konto tylko-do-odczytu (docs/06, D-03) —
        // patrz infra/elasticsearch/setup-security.sh, rola `catalog_app`.
        $this->app->singleton(Client::class, function () {
            $builder = ClientBuilder::create()->setHosts([config('services.elasticsearch.host')]);

            // setBasicAuthentication() ma sygnaturę (string, string) — BEZ `?`.
            // Środowisko bez ustawionych ELASTICSEARCH_USER/PASSWORD (np. świeży
            // checkout bez .env, tak jak teraz w testach) wywaliłoby CAŁĄ apkę
            // TypeError-em przy pierwszym użyciu klienta, zamiast pozwolić
            // HealthController zgłosić to jako pojedynczą, opisaną degradację.
            if (filled(config('services.elasticsearch.user')) && filled(config('services.elasticsearch.password'))) {
                $builder->setBasicAuthentication(
                    config('services.elasticsearch.user'),
                    config('services.elasticsearch.password'),
                );
            }

            return $builder->build();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
