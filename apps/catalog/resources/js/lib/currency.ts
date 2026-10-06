/**
 * Ceny w propsach ETAP 7 (`price_min`/`price_max`/`offers.price`) są w
 * groszach — dokładnie tak, jak `price_cents` w Postgresie i pola
 * `price_min`/`price_max`/`offers.price` w mapowaniu products-v1
 * (App\Services\Search\SearchCriteria — konwersja zł↔grosze dzieje się
 * po stronie frontu, nie w PHP).
 */
export function formatPriceCents(cents: number): string {
    return new Intl.NumberFormat('pl-PL', {
        style: 'currency',
        currency: 'PLN',
    }).format(cents / 100);
}

export function centsFromZloty(zloty: number): number {
    return Math.round(zloty * 100);
}

export function zlotyFromCents(cents: number): number {
    return cents / 100;
}
