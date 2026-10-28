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
    // Tunel POST /_errors (ErrorTunnelController): przeglądarka nie łączy się z GlitchTipem
    // bezpośrednio, a cel jest wpisany tu, nie pochodzi z żądania klienta. Pusty klucz
    // = tunel wyłączony (204). Te same zmienne ustawia compose dla catalog-app.
    'tunnel' => [
        'key' => env('GLITCHTIP_FRONTEND_KEY'),
        'project_id' => env('GLITCHTIP_FRONTEND_PROJECT_ID', '2'),
        'upstream' => env('GLITCHTIP_UPSTREAM', 'glitchtip:8000'),
        // Limity na minutę. GlitchTip self-hosted NIE ogranicza przyjmowania (limit na kluczu
        // DSN jest zapisywany, ale nieegzekwowany: zmierzone na 6.2.6, RUNBOOK #077), więc
        // to jedyna zapora przed zalaniem bazy przez publiczny tunel. Rozmiar: ok. 23 kB na
        // zdarzenie, retencja 14 dni, więc 60/min w skrajnym, ciągłym ataku to ok. 28 GB.
        'rate_per_ip' => (int) env('ERROR_TUNNEL_RATE_PER_IP', 20),
        'rate_global' => (int) env('ERROR_TUNNEL_RATE_GLOBAL', 60),
    ],

    'frontend' => [
        'dsn' => env('SENTRY_FRONTEND_DSN'),
        'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV')),
        'release' => env('SENTRY_RELEASE', env('APP_VERSION', 'dev')),
    ],

];
