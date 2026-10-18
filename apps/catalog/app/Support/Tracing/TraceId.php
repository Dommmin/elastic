<?php

namespace App\Support\Tracing;

use Illuminate\Support\Facades\Context;

/**
 * Identyfikator śledzenia jednego "przypadku użycia" przez żądanie HTTP,
 * outbox, kolejkę AMQP i logi obu aplikacji.
 *
 * DLACZEGO w App\Support, a nie w SharedKernel: korzysta z Laravelowego
 * Context, a SharedKernel nie może zależeć od Illuminate (test architektury).
 *
 * Format zgodny z W3C Trace Context: 32 znaki [0-9a-f], nie same zera —
 * dzięki temu przyjmujemy `traceparent` od zewnętrznego klienta i sami
 * możemy go wystawić dalej.
 */
final class TraceId
{
    public const CONTEXT_KEY = 'trace_id';

    /**
     * Bieżący trace_id; jeśli nikt go jeszcze nie ustawił (np. komenda
     * artisan), zakładamy nowy i zapamiętujemy w Context, by kolejne
     * wywołania w tym samym procesie zwracały to samo.
     */
    public static function current(): string
    {
        $current = Context::get(self::CONTEXT_KEY);

        if (is_string($current) && $current !== '') {
            return $current;
        }

        $generated = self::generate();
        Context::add(self::CONTEXT_KEY, $generated);

        return $generated;
    }

    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Zwraca trace_id z nagłówka `traceparent` albo null, gdy nagłówek jest
     * pusty lub niezgodny ze specyfikacją (wtedy generujemy własny — nie
     * wpuszczamy do logów dowolnych śmieci od klienta).
     */
    public static function fromTraceparent(?string $header): ?string
    {
        if ($header === null) {
            return null;
        }

        if (preg_match('/^00-([0-9a-f]{32})-[0-9a-f]{16}-[0-9a-f]{2}$/D', $header, $m) !== 1) {
            return null;
        }

        return $m[1] === str_repeat('0', 32) ? null : $m[1];
    }

    /**
     * Nagłówek `traceparent` dla wywołania wychodzącego. Span id jest nowy
     * (nie śledzimy spanów), flaga `01` = sampled.
     */
    public static function toTraceparent(string $traceId): string
    {
        return '00-'.$traceId.'-'.bin2hex(random_bytes(8)).'-01';
    }
}
