<?php

namespace App\Providers;

use App\Models\User;
use App\Support\ErrorTracking\QueueRetryFilter;
use Carbon\CarbonImmutable;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Stan wiąże się z obiektem wyjątku (WeakMap), więc singleton jest bezpieczny
        // także w Octane. Listenery w boot(); ADR-0009.
        $this->app->singleton(QueueRetryFilter::class);

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

        Event::listen(JobExceptionOccurred::class, [QueueRetryFilter::class, 'jobExceptionOccurred']);
        Event::listen(JobFailed::class, [QueueRetryFilter::class, 'jobFailed']);

        // Rejestracja jest otwarta, więc samo `auth` nie chroni panelu edycji
        // katalogu — wpuszczamy tylko konta oznaczone przez `marketplace:admin`.
        Gate::define('panel', fn (User $user): bool => $user->is_admin);
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
