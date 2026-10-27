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

    // Release = SHA wdrożonego obrazu. Kolejność: SENTRY_RELEASE (compose.prod.yaml ustawia
    // IMAGE_TAG), potem APP_VERSION wbudowany w obraz przez CI, na końcu `dev` lokalnie.
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

    // Natywny filtr danych SDK (zastępuje opcje powyżej, które zostają jako druga linia
    // obrony). Co kontroluje, a czego nie:
    // - stack_frame_variables: OFF. Obraz FrankenPHP nie ma php.ini, więc
    //   `zend.exception_ignore_args` jest Off i ślad wyjątku niesie wartości argumentów,
    //   np. tablicę z hasłem w `CreateNewUser::create(array $input)`. Bez tego trafiałyby
    //   do GlitchTipa jako zmienne ramek (i do maila o nowym issue).
    // - url_query_params: OFF. Linki Fortify niosą e-mail i podpis w query stringu
    //   (`/reset-password/{token}?email=…`, `/email/verify/{id}/{hash}?signature=…`).
    // - nagłówki: allow-lista, więc `Referer` (ten sam link) i `X-XSRF-TOKEN` odpadają.
    //   SDK dodatkowo zawsze filtruje klucze z `token`, `auth`, `session`, `csrf`…
    // Tokeny w ŚCIEŻCE oraz komunikaty SQL czyści EventScrubber (SDK ich nie rusza).
    'data_collection' => [
        'user_info' => false,
        'cookies' => ['mode' => 'off'],
        'http_headers' => [
            'request' => [
                'mode' => 'allowList',
                'terms' => [
                    'accept', 'content-type', 'content-length', 'host', 'user-agent',
                    'x-requested-with', 'x-inertia', 'x-forwarded-proto', 'x-trace-id', 'traceparent',
                ],
            ],
            'response' => ['mode' => 'off'],
        ],
        'http_bodies' => [],
        'url_query_params' => ['mode' => 'off'],
        'database_query_data' => false,
        'stack_frame_variables' => ['mode' => 'off'],
        'frame_context_lines' => 5,
    ],

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
