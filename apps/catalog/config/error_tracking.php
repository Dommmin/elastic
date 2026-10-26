<?php

/**
 * Ustawienia śledzenia błędów należące do aplikacji, nie do SDK
 * (config/sentry.php odrzuca nieznane klucze). ADR-0009.
 */
return [

    // Który proces raportuje: http | queue | outbox-publisher | scheduler.
    // Ustawia go compose per kontener; filtr po tym tagu w GlitchTipie.
    'process' => env('ERROR_TRACKING_PROCESS', 'http'),

    // Konfiguracja przeglądarki trafia do strony w runtime (meta w app.blade.php),
    // a nie do buildu: ten sam obraz działa w każdym środowisku. Pusty DSN = bez SDK.
    // Przeglądarka wysyła przez tunel /_errors w Caddy, więc host z DSN nie jest
    // używany; liczy się klucz i ID projektu.
    'frontend' => [
        'dsn' => env('SENTRY_FRONTEND_DSN'),
        'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV')),
        'release' => env('SENTRY_RELEASE', env('APP_VERSION', 'dev')),
    ],

];
