export type SellerStatus = 'pending' | 'active' | 'rejected' | 'blocked';

/**
 * Profil sprzedawcy zalogowanego użytkownika — shared prop `auth.seller`
 * (App\Http\CurrentSeller); `null`, gdy użytkownik nie złożył zgłoszenia.
 */
export type SellerProfile = {
    id: number;
    name: string;
    city: string;
    status: SellerStatus;
    status_reason: string | null;
};

/** Wiersz kolejki `pending` w panelu (ReadModel\PendingSellers). */
export type PendingSeller = {
    id: number;
    name: string;
    city: string;
    email: string | null;
    created_at: string | null;
};

/** Wiersz listy `sellers` w panelu (ReadModel\SellerDirectory). */
export type DirectorySeller = {
    id: number;
    name: string;
    city: string;
    status: SellerStatus;
    status_reason: string | null;
    status_changed_at: string | null;
    has_account: boolean;
    email: string | null;
};
