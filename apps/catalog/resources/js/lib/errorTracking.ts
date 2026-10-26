import { router } from '@inertiajs/vue3';
import * as Sentry from '@sentry/vue';
import type { App } from 'vue';
import { scrubBreadcrumb, scrubEvent } from '@/lib/scrubUrl';

/**
 * Błędy z przeglądarki do GlitchTipa (docs/adr/0009-wyjatki-w-glitchtip.md).
 *
 * Przeglądarka NIE łączy się z GlitchTipem bezpośrednio: wysyła przez tunel
 * w Caddy, który ma cel wpisany na sztywno. Dzięki temu GlitchTip nie musi być
 * publiczny, nie ma CORS ani blokad adblockerów, a tunel nie jest otwartym
 * przekaźnikiem. Host z DSN jest więc nieużywany; liczy się klucz i ID projektu.
 */
export const TUNNEL_PATH = '/_errors';

export type ErrorTrackingConfig = {
    dsn: string;
    environment: string;
    release: string;
};

/**
 * Konfiguracja przychodzi w runtime z meta w app.blade.php (nie z buildu), więc
 * ten sam bundle działa w każdym środowisku. Brak DSN = śledzenie wyłączone.
 */
export function readConfig(doc: Document): ErrorTrackingConfig | null {
    const meta = (name: string): string =>
        doc
            .querySelector<HTMLMetaElement>(
                `meta[name="error-tracking-${name}"]`,
            )
            ?.content.trim() ?? '';

    const dsn = meta('dsn');

    if (dsn === '') {
        return null;
    }

    return {
        dsn,
        environment: meta('environment') || 'production',
        release: meta('release') || 'dev',
    };
}

/**
 * Id śladu z odpowiedzi serwera (nagłówek X-Trace-Id z AssignTraceId).
 * Nazwy nagłówków bywają w dowolnej wielkości liter.
 */
export function traceIdOf(
    headers: Record<string, string> | undefined,
): string | undefined {
    if (!headers) {
        return undefined;
    }

    const key = Object.keys(headers).find(
        (name) => name.toLowerCase() === 'x-trace-id',
    );

    return key === undefined ? undefined : headers[key];
}

export function initErrorTracking(app: App): void {
    const config = readConfig(document);

    if (config === null) {
        return;
    }

    Sentry.init({
        app,
        dsn: config.dsn,
        environment: config.environment,
        release: config.release,
        tunnel: TUNNEL_PATH,
        // Bez PII, śladów i nagrań sesji: GlitchTip ma być od wyjątków (ADR-0009).
        sendDefaultPii: false,
        tracesSampleRate: 0,
        maxBreadcrumbs: 50,
        // Tylko nasz kod: odpada szum z rozszerzeń przeglądarki.
        allowUrls: [window.location.origin],
        ignoreErrors: [
            // Nieszkodliwe, zgłaszane przez przeglądarki przy zmianie układu.
            /ResizeObserver loop/,
        ],
        // Tokeny z linków Fortify (reset hasła, weryfikacja e-maila) i query stringi nie
        // mogą trafić do zdarzeń ani breadcrumbs (patrz scrubUrl.ts).
        beforeSend: scrubEvent,
        beforeBreadcrumb: scrubBreadcrumb,
    });

    registerInertiaBreadcrumbs();
}

/**
 * Most do backendu: błąd HTTP z serwera niesie X-Trace-Id, który trafia do
 * breadcrumbs. Późniejszy błąd w przeglądarce pokazuje więc id śladu żądań, które
 * go poprzedziły, i można przejść do Kibany (`trace.id`). Sama odpowiedź 5xx jest
 * już issue w projekcie `catalog`, więc nie raportujemy jej drugi raz.
 *
 * Handlery nic nie zwracają: wartość boolean zmieniłaby domyślną obsługę błędu
 * przez Inertię (modal z odpowiedzią).
 */
function registerInertiaBreadcrumbs(): void {
    router.on('httpException', (event) => {
        const { response } = event.detail;

        Sentry.addBreadcrumb({
            category: 'inertia',
            level: 'error',
            message: 'Odpowiedź HTTP z błędem',
            data: {
                status: response.status,
                trace_id: traceIdOf(response.headers),
            },
        });
    });

    router.on('networkError', (event) => {
        Sentry.addBreadcrumb({
            category: 'inertia',
            level: 'error',
            message: 'Błąd sieci przy wizycie Inertia',
            data: { error: event.detail.error.message },
        });
    });
}
