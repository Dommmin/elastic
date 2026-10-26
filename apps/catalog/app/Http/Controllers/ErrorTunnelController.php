<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

/**
 * Tunel błędów z przeglądarki do GlitchTipa: `POST /_errors` (ADR-0009).
 *
 * SDK w przeglądarce (opcja `tunnel`) wysyła kopertę na to samo origin, a my
 * przekazujemy ją do GlitchTipa. Dzięki temu GlitchTip nie musi być publiczny, nie ma
 * CORS ani mixed content, a adblockery nie widzą domeny `sentry`.
 *
 * To NIE jest otwarty przekaźnik: projekt i klucz pochodzą z configu, nie z żądania
 * (klucz w query stringu, `X-Sentry-Auth` i DSN w kopercie nie zmieniają celu), a
 * cookie, autoryzacja i reszta nagłówków klienta nie idą dalej, bo budujemy własne
 * żądanie. Liczbę żądań ogranicza limiter `errors-tunnel` (AppServiceProvider).
 *
 * Awaria GlitchTipa nie może wydłużać obsługi: timeouty 0,3 s i 1 s jak w SDK
 * (config/sentry.php), a każdy błąd po stronie GlitchTipa to 502, które SDK ignoruje.
 */
final class ErrorTunnelController
{
    private const MAX_BODY_BYTES = 1024 * 1024;

    private const CONNECT_TIMEOUT_SECONDS = 0.3;

    private const TIMEOUT_SECONDS = 1.0;

    public function __invoke(Request $request): Response
    {
        $key = (string) config('error_tracking.tunnel.key');

        // Pusty klucz = tunel wyłączony (np. prod bez GlitchTipa): przeglądarka dostaje 204
        // i nie próbuje ponownie.
        if ($key === '') {
            return response()->noContent();
        }

        // Limit rozmiaru dotyczy bajtów po kompresji, a SDK przeglądarki nie kompresuje,
        // więc skompresowane ciało odrzucamy zamiast ryzykować bombę gzip w GlitchTipie.
        if ($request->headers->has('Content-Encoding')) {
            return response('', 415);
        }

        $body = $request->getContent();

        if (strlen($body) > self::MAX_BODY_BYTES) {
            return response('', 413);
        }

        $url = sprintf(
            'http://%s/api/%s/envelope/?sentry_key=%s',
            config('error_tracking.tunnel.upstream'),
            config('error_tracking.tunnel.project_id'),
            rawurlencode($key),
        );

        try {
            $upstream = Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::TIMEOUT_SECONDS)
                ->withUserAgent($request->userAgent() ?? 'catalog-error-tunnel')
                ->withBody($body, $request->header('Content-Type', 'text/plain;charset=UTF-8'))
                ->post($url);
        } catch (ConnectionException) {
            return response('', 502);
        }

        $status = $upstream->status();

        // 4xx od GlitchTipa (zła koperta, jego własny limit) przekazujemy z kodem; wszystko
        // inne, w tym 5xx, to dla przeglądarki po prostu 502.
        return response('', $status >= 400 && $status < 500 ? $status : ($status >= 500 ? 502 : 200));
    }
}
