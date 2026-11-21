<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Anonimowy identyfikator sesji analitycznej (ETAP 12, spec sekcja 4).
 *
 * Sesja Laravela się nie nadaje: zmienia ID przy logowaniu (ochrona przed
 * session fixation), więc rozcięłaby lejek na "przed" i "po". `mp_sid` to
 * osobne ciasteczko z losowym UUID, niezależne od logowania; 30 minut
 * bezczynności, przedłużane przy każdej odpowiedzi.
 *
 * Octane: żadnego stanu w polach — ID żyje w atrybutach requestu, a ciasteczko
 * dopisujemy wprost do odpowiedzi (grupa `api` nie ma AddQueuedCookiesToResponse).
 * Ciasteczko jest poza EncryptCookies (bootstrap/app.php), żeby web i api czytały
 * tę samą wartość; to losowy UUID bez danych osobowych.
 */
class AnalyticsSession
{
    public const COOKIE = 'mp_sid';

    public const ATTRIBUTE = 'analytics_session_id';

    public const LIFETIME_MINUTES = 30;

    public function handle(Request $request, Closure $next): Response
    {
        $existing = $request->cookie(self::COOKIE);
        $id = is_string($existing) && Str::isUuid($existing) ? strtolower($existing) : (string) Str::uuid();

        $request->attributes->set(self::ATTRIBUTE, $id);

        $response = $next($request);

        $response->headers->setCookie(new Cookie(
            name: self::COOKIE,
            value: $id,
            expire: time() + self::LIFETIME_MINUTES * 60,
            path: '/',
            domain: config('session.domain'),
            secure: (bool) config('session.secure'),
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_LAX,
        ));

        return $response;
    }

    /**
     * ID sesji bieżącego żądania; poza middleware (np. komenda) — świeże, jednorazowe.
     */
    public static function currentId(Request $request): string
    {
        $id = $request->attributes->get(self::ATTRIBUTE);

        return is_string($id) && $id !== '' ? $id : (string) Str::uuid();
    }
}
