<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use Billing\Domain\BillingAccountRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Eksport danych sprzedawców dla projektora `seller-360` w search (ETAP 13):
 * backfill i naprawa po rozjechaniu czytają stąd stan, który normalnie
 * dostarczają zdarzenia `seller.*` i `billing.*`. Token i kursor jak w
 * `ProductIdsController` (middleware `RequireInternalToken` na grupie `internal`).
 */
class SellerExportController extends Controller
{
    private const DEFAULT_LIMIT = 1000;

    /**
     * Keyset po `id` rosnąco; `limit + 1` mówi, czy jest kolejna strona.
     */
    public function sellers(Request $request): JsonResponse
    {
        [$after, $limit] = $this->cursor($request);

        $rows = DB::table('sellers')
            ->select(['id', 'name', 'city', 'nip', 'status', 'status_reason', 'synthetic', 'version'])
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $page = $rows->take($limit);

        return response()->json([
            'items' => $page->map(fn (object $row): array => [
                'seller_id' => (int) $row->id,
                'name' => $row->name,
                'city' => $row->city,
                'nip' => $row->nip,
                'status' => $row->status,
                'status_reason' => $row->status_reason,
                'synthetic' => (bool) $row->synthetic,
                'version' => (int) $row->version,
            ])->values()->all(),
            'next_after' => $rows->count() > $limit ? (int) $page->last()->id : null,
        ]);
    }

    /**
     * Liczniki ofert sprzedawcy. "Wyprzedana" to opublikowana oferta bez
     * stanu (nie ma osobnego statusu), więc `active` liczy tylko opublikowane
     * ze stanem > 0; szkice i wycofane wchodzą wyłącznie do `total`.
     */
    public function offerStats(int $id): JsonResponse
    {
        $stats = DB::table('offers')
            ->where('seller_id', $id)
            ->selectRaw('count(*) as total')
            ->selectRaw("coalesce(sum(case when status = 'published' and stock > 0 then 1 else 0 end), 0) as active")
            ->selectRaw("coalesce(sum(case when status = 'suspended' then 1 else 0 end), 0) as suspended")
            ->selectRaw("coalesce(sum(case when status = 'published' and stock = 0 then 1 else 0 end), 0) as sold_out")
            ->first();

        return response()->json([
            'seller_id' => $id,
            'active' => (int) $stats->active,
            'suspended' => (int) $stats->suspended,
            'sold_out' => (int) $stats->sold_out,
            'total' => (int) $stats->total,
            'computed_at' => now()->format('Y-m-d\TH:i:s.vP'),
        ]);
    }

    public function billingAccounts(Request $request, BillingAccountRepository $accounts): JsonResponse
    {
        [$after, $limit] = $this->cursor($request);

        $page = $accounts->page($after, $limit + 1);
        $hasMore = count($page) > $limit;
        $page = array_slice($page, 0, $limit);

        return response()->json([
            'items' => array_map(fn ($account): array => [
                'seller_id' => $account->sellerId(),
                ...$account->snapshot(),
                'version' => $account->version(),
            ], $page),
            'next_after' => $hasMore ? end($page)->sellerId() : null,
        ]);
    }

    /**
     * @return array{int, int}
     */
    private function cursor(Request $request): array
    {
        $data = $request->validate([
            'after' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'between:1,5000'],
        ]);

        return [(int) ($data['after'] ?? 0), (int) ($data['limit'] ?? self::DEFAULT_LIMIT)];
    }
}
