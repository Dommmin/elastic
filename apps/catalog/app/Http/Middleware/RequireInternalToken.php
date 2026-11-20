<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wspólny sekret dla /api/internal/* (search-service -> catalog).
 *
 * DLACZEGO nie wystarcza blok `http://catalog-app` w Caddyfile: port 80 kontenera
 * jest opublikowany, a blok wybiera nagłówek Host, który klient z zewnątrz może
 * sfałszować (`Host: catalog-app`). Token jest więc prawdziwą granicą, a blokada
 * w publicznym bloku Caddy'ego tylko obroną w głębi.
 *
 * Pusty sekret w konfiguracji to 503 dla każdego (fail closed): inaczej pusty
 * nagłówek przeszedłby porównanie pustych napisów. hash_equals porównuje w czasie
 * stałym, żeby czas odpowiedzi nie zdradzał wspólnego prefiksu tokenu.
 */
class RequireInternalToken
{
    public const HEADER = 'X-Internal-Token';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.internal_api.token');

        if ($expected === '') {
            Log::error('Żądanie /api/internal odrzucone: brak INTERNAL_API_TOKEN w konfiguracji.');

            abort(503, 'Internal API token is not configured.');
        }

        if (! hash_equals($expected, (string) $request->header(self::HEADER, ''))) {
            abort(401);
        }

        return $next($request);
    }
}
