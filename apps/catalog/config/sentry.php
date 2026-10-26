<?php

use App\Support\ErrorTracking\EnrichEvent;

/**
 * Sentry SDK wskazany na self-hosted GlitchTip (docs/adr/0009-wyjatki-w-glitchtip.md).
 *
 * UWAGA: SDK odrzuca nieznane klucze najwyższego poziomu, więc ustawienia
 * aplikacyjne (tag `process`, konfiguracja frontendu) są w config/error_tracking.php.
 *
 * Pusty DSN = klient wyłączony: nic nie jest wysyłane i nic się nie psuje.
 *
 * @see https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/
 */
return [

    'dsn' => env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN')),

    // SHA commita wbudowany w obraz prod (APP_VERSION); lokalnie `dev`.
    'release' => env('SENTRY_RELEASE', env('APP_VERSION', 'dev')),

    // Pusty = środowisko Laravela (APP_ENV).
    'environment' => env('SENTRY_ENVIRONMENT'),

    'sample_rate' => 1.0,

    // Bez śladów, logów i metryk: GlitchTip ma być od wyjątków. Logi są w ES,
    // metryki i latencja w Prometheusie/Caddy (ADR-0009, zasada 2).
    'traces_sample_rate' => 0.0,
    'enable_logs' => false,
    'enable_metrics' => false,

    // Bez PII: nie wysyłamy IP, ciasteczek, nagłówków autoryzacji ani treści żądania.
    'send_default_pii' => false,
    'max_request_body_size' => 'never',

    // Awaria GlitchTipa nie może wydłużyć odpowiedzi ani zablokować workera:
    // SDK wysyła synchronicznie, więc timeouty są twardym ograniczeniem kosztu.
    // Zmierzone na żywym stosie: tuż po zatrzymaniu GlitchTipa Docker jeszcze
    // rozwiązuje nazwę na zwolniony adres, `connect` wisi do końca limitu, a SDK
    // próbuje kilku wysyłek (przy 1 s żądanie z błędem trwało ok. 3 s). Połączenie
    // w sieci dockerowej/klastra trwa milisekundy, więc 0,3 s ma duży zapas.
    'http_connect_timeout' => 0.3,
    'http_timeout' => 1.0,

    // Tag trace_id/process i user.id bez e-maila (patrz klasa).
    'before_send' => [EnrichEvent::class, 'handle'],

    // Breadcrumbs są jedynym sposobem, w jaki logi trafiają do zdarzenia
    // (bez handlera Monologa: ten sam błąd nie może przyjść dwa razy).
    'breadcrumbs' => [
        'logs' => true,
        'cache' => false,
        'livewire' => false,
        'sql_queries' => true,
        'sql_bindings' => false,
        'queue_info' => true,
        'command_info' => true,
        'http_client_requests' => true,
        'notifications' => false,
    ],

    'tracing' => [
        'queue_job_transactions' => false,
        'queue_jobs' => false,
        'sql_queries' => false,
        'sql_bindings' => false,
        'sql_origin' => false,
        'views' => false,
        'livewire' => false,
        'http_client_requests' => false,
        'cache' => false,
        'redis_commands' => false,
        'notifications' => false,
        'missing_routes' => false,
        'default_integrations' => true,
    ],

];
