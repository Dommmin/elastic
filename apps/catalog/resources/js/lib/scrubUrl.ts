import type { Breadcrumb, ErrorEvent } from '@sentry/vue';

/**
 * Czyszczenie adresów w zdarzeniach z przeglądarki (ADR-0009, zasada 6).
 *
 * Linki Fortify niosą sekrety: `/reset-password/{token}?email=…` oraz
 * `/email/verify/{id}/{hash}?expires=…&signature=…`. SDK wysyłałby je w `request.url`,
 * w nagłówku `Referer` i w breadcrumbs (nawigacja, XHR, fetch), więc każdy późniejszy
 * błąd na takiej stronie ujawniałby żywy token. Czyste funkcje bez zależności od SDK
 * w czasie wykonania, żeby dało się je sprawdzić `node --test` (`npm run test:js`).
 */
const TOKEN_PATHS = /(\/(?:reset-password|email\/verify)\/)[^?#]*/;

/** Bez query stringa i fragmentu; sekret w ścieżce zastąpiony `[Filtered]`. */
export function scrubUrl(url: string): string {
    const withoutQueryAndFragment = url.split(/[?#]/)[0];

    return withoutQueryAndFragment.replace(TOKEN_PATHS, '$1[Filtered]');
}

const URL_KEYS = ['url', 'from', 'to'] as const;

export function scrubBreadcrumb(breadcrumb: Breadcrumb): Breadcrumb {
    if (!breadcrumb.data) {
        return breadcrumb;
    }

    const data = { ...breadcrumb.data };

    for (const key of URL_KEYS) {
        if (typeof data[key] === 'string') {
            data[key] = scrubUrl(data[key]);
        }
    }

    return { ...breadcrumb, data };
}

export function scrubEvent<T extends ErrorEvent>(event: T): T {
    if (event.request) {
        const request = { ...event.request };

        if (typeof request.url === 'string') {
            request.url = scrubUrl(request.url);
        }

        delete request.query_string;

        if (request.headers) {
            request.headers = Object.fromEntries(
                Object.entries(request.headers).filter(
                    ([name]) => name.toLowerCase() !== 'referer',
                ),
            );
        }

        event = { ...event, request };
    }

    if (event.breadcrumbs) {
        event = {
            ...event,
            breadcrumbs: event.breadcrumbs.map(scrubBreadcrumb),
        };
    }

    return event;
}
