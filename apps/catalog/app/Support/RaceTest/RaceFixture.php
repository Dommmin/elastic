<?php

namespace App\Support\RaceTest;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Dane startowe `orders:race-test`: N kupujących, sprzedawca bez konta,
 * produkt i opublikowana oferta z zadanym stanem.
 *
 * DLACZEGO wiersze, a nie handlery portalu: dane startowe nie są mierzone.
 * Fixture jest oznaczone (`race-{run}-{n}@example.test`, slug `race-{run}`)
 * i sprzątane wyłącznie po ID, które tu powstały, więc nie dotyka cudzych danych.
 */
final readonly class RaceFixture
{
    public const int PRICE_CENTS = 1000;

    public const string CURRENCY = 'PLN';

    /**
     * @param  list<int>  $userIds
     */
    private function __construct(
        public string $run,
        public array $userIds,
        public int $sellerId,
        public int $productId,
        public int $offerId,
    ) {}

    /**
     * W jednej transakcji: błąd w połowie nie zostawia niczego do sprzątania.
     */
    public static function create(string $run, int $buyers, int $stock): self
    {
        return DB::transaction(function () use ($run, $buyers, $stock): self {
            $now = now();
            $password = Hash::make(Str::random(40));
            $users = [];

            for ($n = 1; $n <= $buyers; $n++) {
                $users[] = (int) DB::table('users')->insertGetId([
                    'name' => "Race {$run} #{$n}",
                    'email' => "race-{$run}-{$n}@example.test",
                    'email_verified_at' => $now,
                    'password' => $password,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // Sprzedawca bez konta: nikt z fixture nie kupuje „własnej” oferty.
            $seller = (int) DB::table('sellers')->insertGetId([
                'name' => "Race {$run}",
                'slug' => "race-{$run}",
                'city' => 'Warszawa',
                'status' => 'active',
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $product = (int) DB::table('products')->insertGetId([
                'name' => "Race test {$run}",
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $offer = (int) DB::table('offers')->insertGetId([
                'product_id' => $product,
                'seller_id' => $seller,
                'price_cents' => self::PRICE_CENTS,
                'currency' => self::CURRENCY,
                'stock' => $stock,
                'condition' => 'new',
                'shipping_days' => 1,
                'status' => 'published',
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return new self($run, $users, $seller, $product, $offer);
        });
    }

    /**
     * Kasuje wiersze fixture i jego nieopublikowane zdarzenia ofert.
     *
     * DLACZEGO jednak `product.deleted` w outboxie: zdarzenia rezerwacji
     * oferty mogły już wyjść do wyszukiwarki i zaindeksować „Race test
     * {run}”. Skasowany po cichu produkt zostałby w indeksie na zawsze;
     * `product.deleted` każe projekcji odpytać katalog, a 404 usuwa dokument.
     * Produkt kasujemy więc przez `deleteWithOutbox` (ten sam mechanizm co
     * reszta katalogu), a pozostałe wiersze Query Builderem.
     */
    public function cleanUp(): void
    {
        DB::transaction(function (): void {
            $orderIds = DB::table('orders')->whereIn('buyer_user_id', $this->userIds)->pluck('id')->all();

            foreach (['order_events', 'order_lines', 'order_refunds'] as $table) {
                DB::table($table)->whereIn('order_id', $orderIds)->delete();
            }

            DB::table('orders')->whereIn('id', $orderIds)->delete();
            DB::table('cart_items')->whereIn('user_id', $this->userIds)->delete();

            foreach (['offer_reservations', 'offer_price_changes', 'cart_items'] as $table) {
                DB::table($table)->where('offer_id', $this->offerId)->delete();
            }

            DB::table('outbox')->whereNull('published_at')
                ->where(fn ($q) => $q
                    ->where(fn ($q) => $q->where('aggregate_type', 'offer')->where('aggregate_id', (string) $this->offerId))
                    ->orWhere(fn ($q) => $q->where('aggregate_type', 'seller')->where('aggregate_id', (string) $this->sellerId)))
                ->delete();

            DB::table('offers')->where('id', $this->offerId)->delete();
            Product::query()->find($this->productId)?->deleteWithOutbox('product.deleted');
            DB::table('sellers')->where('id', $this->sellerId)->delete();
            DB::table('users')->whereIn('id', $this->userIds)->delete();
        });
    }
}
