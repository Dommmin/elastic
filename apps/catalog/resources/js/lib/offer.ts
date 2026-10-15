import type { OfferCondition, OfferFilter, OfferStatus } from '@/types';

export const offerStatusLabels: Record<OfferStatus, string> = {
    draft: 'szkic',
    published: 'opublikowana',
    suspended: 'zawieszona',
    withdrawn: 'wycofana',
};

export const offerFilterLabels: Record<OfferFilter, string> = {
    draft: 'Szkice',
    published: 'Opublikowane',
    sold_out: 'Wyprzedane',
    suspended: 'Zawieszone',
    withdrawn: 'Wycofane',
    pending_price: 'Cena u moderatora',
};

export const conditionLabels: Record<OfferCondition, string> = {
    new: 'nowy',
    used: 'używany',
};

export const timelineEventLabels: Record<string, string> = {
    'offer.drafted': 'Utworzono szkic',
    'offer.published': 'Opublikowano',
    'offer.price_changed': 'Zmieniono cenę',
    'offer.price_change_requested': 'Zgłoszono zmianę ceny',
    'offer.price_change_approved': 'Moderator zaakceptował cenę',
    'offer.price_change_rejected': 'Moderator odrzucił cenę',
    'offer.price_change_cancelled': 'Anulowano zgłoszenie ceny',
    'offer.stock_changed': 'Zmieniono stan magazynowy',
    'offer.sold_out': 'Wyprzedano',
    'offer.back_in_stock': 'Towar wrócił na stan',
    'offer.details_changed': 'Zmieniono szczegóły',
    'offer.suspended': 'Zawieszono',
    'offer.reinstated': 'Przywrócono',
    'offer.withdrawn': 'Wycofano',
};

/**
 * Ta sama normalizacja co `NormalizesPrice` po stronie serwera: spacje
 * (także twarde) out, przecinek → kropka, najwyżej 2 miejsca, > 0.
 * Zwraca `null`, gdy wpis nie jest poprawną ceną — wtedy podpowiedź milczy,
 * a komunikat da walidacja serwera.
 */
export function parsePriceToCents(input: string): number | null {
    const normalized = input.replace(/[\s  ]+/g, '').replace(',', '.');

    if (!/^\d+(\.\d{1,2})?$/.test(normalized)) {
        return null;
    }

    const cents = Math.round(Number(normalized) * 100);

    return cents > 0 ? cents : null;
}

/** „pozostała 1 zmiana”, „pozostały 3 zmiany”, „pozostało 5 zmian”. */
export function changesLeftLabel(count: number): string {
    if (count === 1) {
        return 'pozostała 1 zmiana';
    }

    const lastDigit = count % 10;
    const lastTwo = count % 100;

    if (lastDigit >= 2 && lastDigit <= 4 && (lastTwo < 12 || lastTwo > 14)) {
        return `pozostały ${count} zmiany`;
    }

    return `pozostało ${count} zmian`;
}
