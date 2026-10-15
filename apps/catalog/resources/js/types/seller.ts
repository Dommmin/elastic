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

export type OfferStatus = 'draft' | 'published' | 'suspended' | 'withdrawn';

export type OfferCondition = 'new' | 'used';

/** Filtry listy ofert (ReadModel\SellerOfferList::FILTERS). */
export type OfferFilter = OfferStatus | 'sold_out' | 'pending_price';

/** Akcje sprzedawcy z `Offer::allowedActionsFor(Actor::Seller)`. */
export type OfferAction =
    | 'publish'
    | 'change_price'
    | 'cancel_pending_price_change'
    | 'change_stock'
    | 'change_details'
    | 'withdraw';

/** Wiersz listy `seller/offers` (ReadModel\SellerOfferList). */
export type SellerOfferRow = {
    id: number;
    product: { id: number; name: string };
    price_cents: number;
    currency: string;
    stock: number;
    status: OfferStatus;
    is_listed: boolean;
    is_sold_out: boolean;
    pending_price_cents: number | null;
    updated_at: string | null;
};

/** Prop `offer` strony `seller/offers/Show` (ReadModel\SellerOfferDetails). */
export type SellerOfferDetails = {
    id: number;
    product: { id: number; name: string };
    price_cents: number;
    currency: string;
    stock: number;
    condition: OfferCondition;
    shipping_days: number;
    status: OfferStatus;
    is_sold_out: boolean;
    is_listed: boolean;
    suspension: {
        kind: 'moderation' | 'seller_blocked';
        note: string | null;
    } | null;
    pending_price: { price_cents: number; requested_at: string } | null;
    version: number;
};

/** Próg i licznik strażnika ceny — liczone na serwerze z `PriceChangePolicy`. */
export type PriceGuard = {
    threshold_percent: number;
    changes_left_today: number;
};

/** Wiersz outboxu oferty, najnowszy pierwszy. */
export type OfferTimelineEvent = {
    id: number;
    type: string;
    occurred_at: string;
    published: boolean;
};

export type SellerDashboardStats = Record<
    OfferStatus | 'sold_out' | 'pending_price',
    number
>;

/** Podpowiedź z `GET /seller/products/lookup` (ReadModel\ProductLookup). */
export type ProductLookupHit = {
    id: number;
    name: string;
    brand: string | null;
    ean: string | null;
};
