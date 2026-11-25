<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Zmienne ustawione w compose.yaml (profil `apps`, serwis catalog-app).
    // Publikator outboxu (App\Console\Commands\PublishOutbox) czyta stąd,
    // nie bezpośrednio z env() — żeby działać poprawnie z `config:cache`.
    'rabbitmq' => [
        'host' => env('RABBITMQ_HOST', 'rabbitmq'),
        'port' => (int) env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'guest'),
        'password' => env('RABBITMQ_PASSWORD', 'guest'),
        'vhost' => env('RABBITMQ_VHOST', '/'),
        // Zgodne z infra/rabbitmq/definitions.template.json (docs/01-INFRASTRUKTURA.md).
        'events_exchange' => env('RABBITMQ_EVENTS_EXCHANGE', 'marketplace.events'),
    ],

    'elasticsearch' => [
        'host' => env('ELASTICSEARCH_HOST', 'http://es01:9200'),
        'user' => env('ELASTICSEARCH_USER'),
        'password' => env('ELASTICSEARCH_PASSWORD'),
    ],

    // ETAP 13: konto `catalog_seller_view` (rola seller_stats_view: read tylko na seller-360-public),
    // host ten sam co wyżej. Klient `es.seller_view` w AppServiceProvider.
    'elasticsearch_seller_view' => [
        'user' => env('ELASTICSEARCH_SELLER_VIEW_USER'),
        'password' => env('ELASTICSEARCH_SELLER_VIEW_PASSWORD'),
    ],

    // ETAP 14: osobny węzeł laboratoryjny es-lab (trial/ML), tylko dla `search:eval:lab`.
    // Klient `es.lab` w AppServiceProvider, wstrzykiwany wyłącznie do LabEvalRunner.
    'elasticsearch_lab' => [
        'host' => env('ELASTICSEARCH_LAB_HOST', 'http://es-lab:9200'),
        'user' => 'elastic',
        'password' => env('ELASTICSEARCH_LAB_PASSWORD'),
    ],

    // Waga popularności (rank_feature) w rankingu zapytań tekstowych; 0 = wyłączone.
    'search' => [
        'popularity_boost' => (float) env('SEARCH_POPULARITY_BOOST', 0),
    ],

    // Wspólny sekret dla /api/internal/* (search-service -> catalog), nagłówek
    // X-Internal-Token. Pusty = endpointy odpowiadają 503 (RequireInternalToken).
    'internal_api' => [
        'token' => env('INTERNAL_API_TOKEN'),
    ],

    // Pepper do HMAC adresu IP recenzji (ETAP 15, app/Security/ClientFingerprint). Tylko w `.env`.
    // Pusty = hasz IP nie jest zapisywany (recenzja i tak się zapisuje, log warning).
    'security' => [
        'ip_pepper' => env('SECURITY_IP_PEPPER'),
    ],

    // Symulowana bramka płatności (app/FakePaymentGateway, spec §6).
    // `webhook_url` w Compose wskazuje wewnętrzny adres catalog-app w sieci
    // `marketplace` (bez TLS), a nie publiczną nazwę przeglądarkową.
    'fake_gateway' => [
        'webhook_secret' => env('FAKE_GATEWAY_WEBHOOK_SECRET'),
        'webhook_url' => env('FAKE_GATEWAY_WEBHOOK_URL', 'http://localhost/webhooks/payments'),
        'webhook_delay_seconds' => (int) env('FAKE_GATEWAY_WEBHOOK_DELAY_SECONDS', 2),
        // Połączenie kolejki, które czyta catalog-worker (webhook wysyła job).
        'queue_connection' => env('FAKE_GATEWAY_QUEUE_CONNECTION', 'database'),
        'fail_first_refund' => (bool) env('FAKE_GATEWAY_FAIL_FIRST_REFUND', false),
    ],

];
