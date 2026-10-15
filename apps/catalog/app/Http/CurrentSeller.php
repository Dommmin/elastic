<?php

namespace App\Http;

use Illuminate\Http\Request;
use SellerPortal\Infrastructure\ReadModel\SellerDirectory;

/**
 * Profil sprzedawcy zalogowanego użytkownika, liczony raz na żądanie.
 *
 * DLACZEGO atrybut żądania, a nie pole klasy: pod Octane obiekty żyją między
 * żądaniami, a `Request` jest świeży dla każdego — middleware `seller`
 * i współdzielony prop `auth.seller` dzielą jedno zapytanie bez ryzyka,
 * że użytkownik zobaczy profil poprzedniego.
 */
final readonly class CurrentSeller
{
    private const string ATTRIBUTE = 'seller_portal.current_seller';

    public function __construct(private SellerDirectory $directory) {}

    /**
     * @return array{id: int, name: string, city: string, status: string, status_reason: string|null}|null
     */
    public function of(Request $request): ?array
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        if (! $request->attributes->has(self::ATTRIBUTE)) {
            $request->attributes->set(self::ATTRIBUTE, $this->directory->forUser((int) $user->getAuthIdentifier()));
        }

        return $request->attributes->get(self::ATTRIBUTE);
    }
}
