/**
 * „3 min temu” dla znaczników świeżości sekcji seller-360 (`_meta.updated_at`).
 */
const formatter = new Intl.RelativeTimeFormat('pl-PL', { numeric: 'auto' });

export function relativeTime(
    iso: string | null,
    now: Date = new Date(),
): string {
    if (!iso) {
        return '—';
    }

    const seconds = Math.round(
        (new Date(iso).getTime() - now.getTime()) / 1000,
    );
    const abs = Math.abs(seconds);

    if (abs < 60) {
        return formatter.format(seconds, 'second');
    }

    if (abs < 3600) {
        return formatter.format(Math.round(seconds / 60), 'minute');
    }

    if (abs < 86400) {
        return formatter.format(Math.round(seconds / 3600), 'hour');
    }

    return formatter.format(Math.round(seconds / 86400), 'day');
}
