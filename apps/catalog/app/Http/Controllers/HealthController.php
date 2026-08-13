<?php

namespace App\Http\Controllers;

use Elastic\Elasticsearch\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Readiness check z degradacją (docs/02-APLIKACJE.md, tabela API: "GET
 * /health zależności: PG, Redis, ES, RabbitMQ (z degradacją!)").
 *
 * RÓŻNICA względem wbudowanego `/up` Laravela (bootstrap/app.php):
 * `/up` odpowiada na pytanie "czy proces PHP w ogóle żyje" — używa go
 * Docker HEALTHCHECK (infra/php/catalog.Dockerfile), bo sprawdzanie
 * zależności sieciowych w liveness probe to prosta droga do lawiny
 * restartów, gdy jedna z nich zwolni na chwilę.
 * `/health` odpowiada na "czy wszystkie zależności działają" — do
 * monitoringu i load balancera (readiness), nie do orkiestracji kontenera.
 *
 * DEGRADACJA: tylko Postgres jest KRYTYCZNY (bez bazy apka w ogóle nie
 * działa -> 503). Redis/Elasticsearch/RabbitMQ down = funkcje pomocnicze
 * (cache, wyszukiwanie, kolejka zdarzeń) tracą dostępność, ale sprzedaż
 * i przeglądanie kart produktów (czytane z Postgresa) działają dalej —
 * dokładnie zasada z docs/05-SPOJNOSC-DANYCH.md, sekcja 8.
 */
class HealthController extends Controller
{
    public function __invoke(Client $elasticsearch): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'elasticsearch' => $this->checkElasticsearch($elasticsearch),
            'rabbitmq' => $this->checkRabbitmq(),
        ];

        $critical = $checks['database']['status'] === 'ok';
        $allOk = $critical && collect($checks)->every(fn (array $c) => $c['status'] === 'ok');

        $status = ! $critical ? 'down' : ($allOk ? 'ok' : 'degraded');

        return response()->json([
            'status' => $status,
            'checks' => $checks,
        ], $critical ? 200 : 503);
    }

    /**
     * @return array{status: string, latency_ms?: float, error?: string}
     */
    private function checkDatabase(): array
    {
        return $this->timed(function () {
            DB::connection()->getPdo();
        });
    }

    private function checkRedis(): array
    {
        return $this->timed(function () {
            Redis::connection()->ping();
        });
    }

    private function checkElasticsearch(Client $client): array
    {
        return $this->timed(function () use ($client) {
            $client->ping();
        });
    }

    /**
     * Sprawdzenie na poziomie TCP, celowo płytkie — pełny handshake AMQP
     * (login, otwarcie kanału) na potrzeby samego health checka byłby
     * nieproporcjonalnie kosztowny wywoływany co kilkanaście sekund.
     * Sprawdza tylko "czy port w ogóle nasłuchuje", nie autentykację.
     */
    private function checkRabbitmq(): array
    {
        return $this->timed(function () {
            $socket = @fsockopen(
                config('services.rabbitmq.host'),
                config('services.rabbitmq.port'),
                $errorCode,
                $errorMessage,
                timeout: 1.5,
            );

            if ($socket === false) {
                throw new \RuntimeException($errorMessage ?: 'connection failed');
            }

            fclose($socket);
        });
    }

    /**
     * @return array{status: string, latency_ms?: float, error?: string}
     */
    private function timed(callable $probe): array
    {
        $start = microtime(true);

        try {
            $probe();

            return [
                'status' => 'ok',
                'latency_ms' => round((microtime(true) - $start) * 1000, 1),
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'down',
                'error' => $e->getMessage(),
            ];
        }
    }
}
