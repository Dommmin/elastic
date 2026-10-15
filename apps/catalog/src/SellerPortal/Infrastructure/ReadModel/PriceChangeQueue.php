<?php

namespace SellerPortal\Infrastructure\ReadModel;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Zmiany ceny czekające na moderatora (powyżej progu strażnika).
 *
 * DLACZEGO najstarsze pierwsze: sprzedawca czeka z ceną zamrożoną, więc
 * kolejka ma być sprawiedliwa. Bez filtra statusu — moderator rozstrzyga
 * zgłoszenie także na zawieszonej ofercie (agregat na to pozwala), a
 * wycofanie oferty i tak czyści oczekującą cenę.
 */
final class PriceChangeQueue
{
    /**
     * `change_percent` to zaokrąglona wartość bezwzględna — kierunek UI
     * odczyta z porównania cen. `version` i `proposed_price_cents` wracają
     * z decyzją moderatora, żeby nie zatwierdził zgłoszenia, którego nie widział.
     *
     * @return list<array{offer_id: int, product: array{id: int, name: string}, seller: array{id: int, name: string}, current_price_cents: int, proposed_price_cents: int, change_percent: int, requested_at: string, version: int}>
     */
    public function all(): array
    {
        return $this->pending()
            ->join('products', 'products.id', '=', 'offers.product_id')
            ->join('sellers', 'sellers.id', '=', 'offers.seller_id')
            ->orderBy('offers.pending_requested_at')
            ->orderBy('offers.id')
            ->get([
                'offers.id', 'offers.product_id', 'products.name as product_name', 'offers.seller_id',
                'sellers.name as seller_name', 'offers.price_cents', 'offers.version', 'offers.pending_price_cents', 'offers.pending_requested_at',
            ])
            ->map(function (object $row): array {
                $current = (int) $row->price_cents;
                $proposed = (int) $row->pending_price_cents;

                return [
                    'offer_id' => (int) $row->id,
                    'product' => ['id' => (int) $row->product_id, 'name' => (string) $row->product_name],
                    'seller' => ['id' => (int) $row->seller_id, 'name' => (string) $row->seller_name],
                    'current_price_cents' => $current,
                    'proposed_price_cents' => $proposed,
                    'change_percent' => (int) round(abs($proposed - $current) * 100 / $current),
                    'requested_at' => Carbon::parse($row->pending_requested_at)->toIso8601ZuluString('microsecond'),
                    'version' => (int) $row->version,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Licznik do menu panelu — jedno `COUNT`, bez złączeń.
     */
    public function count(): int
    {
        return $this->pending()->count();
    }

    private function pending(): Builder
    {
        return DB::table('offers')->whereNotNull('offers.pending_price_cents');
    }
}
