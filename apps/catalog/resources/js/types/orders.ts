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

/**
 * Zamówienia kupującego (App\Http\Controllers\Shop\OrderController,
 * Ordering\Infrastructure\ReadModel\{BuyerOrderList, BuyerOrderDetails}).
 */

export type OrderStatus =
    | 'placed'
    | 'awaiting_payment'
    | 'paid'
    | 'shipped'
    | 'rejected'
    | 'expired'
    | 'cancelled';

export type OrderActionName = 'pay' | 'cancel';

export interface OrderListItem {
    id: number;
    status: OrderStatus;
    status_label: string;
    seller: string;
    total_cents: number;
    placed_at: string;
    lines_count: number;
    first_product: string | null;
}

export interface OrderDetailsLine {
    offer_id: number;
    product_name: string;
    unit_price_cents: number;
    quantity: number;
}

export interface OrderRefund {
    amount_cents: number;
    status: 'requested' | 'completed';
}

export interface OrderDetails {
    id: number;
    status: OrderStatus;
    status_label: string;
    seller: { id: number; name: string };
    lines: OrderDetailsLine[];
    total_cents: number;
    payment_deadline: string;
    /** Liczone na serwerze: odliczanie nie ufa zegarowi przeglądarki. */
    seconds_to_deadline: number;
    refunds: OrderRefund[];
    tracking_number: string | null;
    rejection_label: string | null;
    cancellation_label: string | null;
}

export interface OrderTimelineEvent {
    id: number;
    type: string;
    occurred_at: string;
}
