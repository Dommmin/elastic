<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Obrona w głębi dla /prometheus: 404, jeśli żądanie nie przyszło na wewnętrzny
 * port metryk (Caddyfile: `http://:2020`, nieopublikowany na hosta).
 *
 * UWAGA co do siły tej ochrony (sprawdzone na obrazie FrankenPHP): PHP nie widzi
 * portu gniazda, tylko port z nagłówka Host (SERVER_PORT = port z Host, a bez
 * niego 80/443). Prometheus wysyła `Host: catalog-app:2020`, więc przechodzi,
 * ale klient z zewnątrz może sfałszować `Host: x:2020` na porcie 80. Ten
 * middleware łapie więc pomyłki (np. ktoś doda trasę /prometheus do bloku
 * publicznego), a PRAWDZIWĄ ochroną pozostaje to, że port 2020 nie jest
 * publikowany (Caddyfile, compose).
 */
class OnlyMetricsPort
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->getPort() === (int) config('prometheus.internal_port'), 404);

        return $next($request);
    }
}
