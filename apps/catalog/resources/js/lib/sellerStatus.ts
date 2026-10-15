import type { SellerStatus } from '@/types';

export const sellerStatusLabels: Record<SellerStatus, string> = {
    pending: 'oczekuje',
    active: 'aktywny',
    rejected: 'odrzucony',
    blocked: 'zablokowany',
};

export function sellerStatusVariant(
    status: SellerStatus,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'active':
            return 'default';
        case 'blocked':
        case 'rejected':
            return 'destructive';
        default:
            return 'secondary';
    }
}

/**
 * Wejście do portalu: aktywny i zablokowany mają pulpit (`seller.approved`
 * ich wpuszcza), oczekujący i odrzucony widzą tylko ekran statusu.
 */
export function hasSellerPortal(status: SellerStatus): boolean {
    return status === 'active' || status === 'blocked';
}
