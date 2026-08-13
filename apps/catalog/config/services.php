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

];
