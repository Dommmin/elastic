<?php

namespace App\Services\Search;

use App\Models\Outbox;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * ID produktów dotkniętych od chwili `since` według outboxu — jedna reguła dla
 * `search:verify-convergence` (dowód zbieżności) i `GET /api/internal/products/changed`
 * (ograniczone nadrabianie po reindex-abort, ETAP 10 Task 12b).
 *
 * Reguła jak `ProductSyncHandler::resolveProductId` w search-service:
 * `product` → aggregate_id (także product.deleted — usunięty produkt też trzeba
 * nadrobić), `offer`/`review` → payload.product_id, reszta (seller, category)
 * to fan-out i nie wskazuje produktu.
 *
 * DISTINCT, kursor i limit liczy baza: okno obejmujące seed to ~300 tys. ID,
 * których nie wolno ładować do PHP na każdą stronę (128 MB pamięci — sprawdzone
 * na żywo). Wyrażenie JSON składa gramatyka zapytań, więc działa na Postgresie
 * (`->>`) i SQLite w testach (`json_extract`). CAST do BIGINT: ID są liczbowe
 * z konstrukcji (klucz produktu, `product_id` z kodu catalogu); śmieci w
 * payloadzie skończą się błędem zapytania (500), nie cichym pominięciem.
 */
class TouchedProductIds
{
    /**
     * @param  int  $after  kursor keysetu: tylko ID większe od niego
     * @param  int|null  $limit  null = wszystkie (verify-convergence)
     * @return list<int> rosnąco, bez dubli
     */
    public function since(CarbonInterface $since, int $after = 0, ?int $limit = null): array
    {
        $events = Outbox::query()->getQuery();
        $payloadProductId = $events->getGrammar()->wrap('payload->product_id');
        $events
            ->selectRaw("CAST(CASE WHEN aggregate_type = 'product' THEN aggregate_id ELSE {$payloadProductId} END AS BIGINT) AS product_id")
            ->where('occurred_at', '>=', $since)
            ->whereIn('aggregate_type', ['product', 'offer', 'review']);

        return DB::query()
            ->fromSub($events, 'touched')
            ->whereNotNull('product_id')
            ->where('product_id', '>', $after)
            ->distinct()
            ->orderBy('product_id')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
