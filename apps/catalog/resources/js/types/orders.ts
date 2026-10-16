/**
 * Kształt propsów kupującego: strona produktu i koszyk
 * (App\Http\Controllers\Shop, Ordering\Infrastructure\ReadModel\CartView).
 */

export interface ShopProduct {
    id: number;
    name: string;
    description: string | null;
    brand: string | null;
}

export interface ShopOffer {
    id: number;
    seller: { id: number; name: string };
    price_cents: number;
    condition: 'new' | 'used';
    shipping_days: number;
    available: number;
    /** min(10, dostępne): górna granica wyboru ilości. */
    max_quantity: number;
    /** Oferta zalogowanego użytkownika: nie kupi własnej. */
    is_own: boolean;
}

export type CartLineStatus = 'ok' | 'price_changed' | 'unavailable';

export interface CartLine {
    offer_id: number;
    product_id: number;
    product_name: string;
    unit_price_cents: number;
    quantity: number;
    available: number;
    status: CartLineStatus;
    /** Cena zaakceptowana przez kupującego; różni się od `unit_price_cents` przy `price_changed`. */
    added_price_cents: number;
}

export interface CartGroup {
    seller: { id: number; name: string };
    subtotal_cents: number;
    lines: CartLine[];
}
