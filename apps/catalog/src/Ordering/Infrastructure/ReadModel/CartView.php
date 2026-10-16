<?php

namespace Ordering\Infrastructure\ReadModel;

use App\Models\CartItem;
use App\Models\Offer;

/**
 * Model odczytu koszyka: pozycje z AKTUALNYMI cenami i dostępnością,
 * pogrupowane per sprzedawca (jedna grupa = jedno przyszłe zamówienie).
 *
 * DLACZEGO status liczony tu, a nie zapamiętany: cena i widoczność oferty
 * zmieniają się poza koszykiem. `price_changed` to różnica między ceną
 * oferty a `added_price_cents`, czyli ceną zaakceptowaną przez kupującego;
 * `unavailable` to oferta spoza scope'u `listed` (ta sama definicja, którą
 * stosuje składanie zamówienia).
 */
final class CartView
{
    public const string OK = 'ok';

    public const string PRICE_CHANGED = 'price_changed';

    public const string UNAVAILABLE = 'unavailable';

    /**
     * @return list<array{
     *     seller: array{id: int, name: string},
     *     subtotal_cents: int,
     *     lines: list<array{offer_id: int, product_id: int, product_name: string, unit_price_cents: int, quantity: int, available: int, status: string, added_price_cents: int}>
     * }>
     */
    public function forBuyer(int $userId): array
    {
        $listed = Offer::query()->listed()->select('offers.id');

        $rows = CartItem::query()
            ->join('offers', 'offers.id', '=', 'cart_items.offer_id')
            ->join('products', 'products.id', '=', 'offers.product_id')
            ->join('sellers', 'sellers.id', '=', 'offers.seller_id')
            ->where('cart_items.user_id', $userId)
            ->select(
                'cart_items.offer_id',
                'cart_items.quantity',
                'cart_items.added_price_cents',
                'offers.price_cents',
                'offers.seller_id',
                'sellers.name as seller_name',
                'products.id as product_id',
                'products.name as product_name',
            )
            ->selectRaw('offers.id in ('.$listed->toSql().') as is_listed', $listed->getBindings())
            ->orderBy('offers.seller_id')
            ->orderBy('cart_items.id')
            ->get();

        $available = Offer::query()->withAvailable()->whereIn('offers.id', $rows->pluck('offer_id'))
            ->get()->pluck('available', 'id');

        $groups = [];

        foreach ($rows as $row) {
            $price = (int) $row->price_cents;
            $status = match (true) {
                ! $row->is_listed => self::UNAVAILABLE,
                $price !== (int) $row->added_price_cents => self::PRICE_CHANGED,
                default => self::OK,
            };
            $sellerId = (int) $row->seller_id;

            $groups[$sellerId] ??= [
                'seller' => ['id' => $sellerId, 'name' => $row->seller_name],
                'subtotal_cents' => 0,
                'lines' => [],
            ];

            $groups[$sellerId]['lines'][] = [
                'offer_id' => (int) $row->offer_id,
                'product_id' => (int) $row->product_id,
                'product_name' => $row->product_name,
                'unit_price_cents' => $price,
                'quantity' => (int) $row->quantity,
                'available' => $row->is_listed ? max(0, (int) $available[$row->offer_id]) : 0,
                'status' => $status,
                'added_price_cents' => (int) $row->added_price_cents,
            ];

            if ($status !== self::UNAVAILABLE) {
                $groups[$sellerId]['subtotal_cents'] += $price * (int) $row->quantity;
            }
        }

        return array_values($groups);
    }
}
