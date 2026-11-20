<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Services\Search\ProductProjectionBuilder;
use App\Support\DatabaseClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Read-back API" (docs/02-APLIKACJE.md, sekcja 7.1, wariant B) — jedyny
 * sposób, w jaki search-service (Symfony) dowiaduje się, jak wygląda
 * produkt, żeby zbudować z niego dokument Elasticsearcha. Symfony NIE ma
 * bezpośredniego dostępu do bazy `catalog` (D-02: kontraktem między
 * serwisami są zdarzenia i to API, nigdy współdzielony schemat bazy).
 *
 * Dostępne TYLKO w sieci dockerowej (brak publicznego routingu na ten
 * prefix — patrz routes/api.php). To nie jest zabezpieczenie samo w sobie,
 * tylko topologia; prawdziwa izolacja przyjdzie z API keys w ETAPIE 12.
 *
 * Kształt odpowiedzi jest zaprojektowany pod mapowanie `products-v1`
 * (infra/elasticsearch/mappings/products-v1.json) — search-service
 * w większości przypadków tylko przepisuje te pola do `_source`.
 */
class ProductProjectionController extends Controller
{
    public function show(int $id, DatabaseClock $clock, ProductProjectionBuilder $builder): JsonResponse
    {
        // Czas MUSI być zmierzony przed odczytem danych produktu. Postgres
        // działa w READ COMMITTED: zapis, który zatwierdzi się po tym pomiarze,
        // mógł nie trafić do odczytu, ale jego projekcja dostanie późniejszy
        // czas i nadpisze tę wersję w ES. Pomiar po odczycie mógłby oznaczyć
        // starsze dane nowszą wersją i zablokować aktualizację.
        $projectedAtUs = $clock->nowMicros();

        $projection = $builder->build($id, $projectedAtUs);

        // Ciało 404 też niesie czas: search-service używa go jako wersji
        // przy usuwaniu dokumentu z ES, więc spóźnione usunięcie nie skasuje
        // nowszego dokumentu.
        if ($projection === null) {
            return response()->json(['projected_at_us' => $projectedAtUs], 404);
        }

        // JSON_PRESERVE_ZERO_FRACTION: bez tej flagi PHP koduje float 4.0
        // jako "4", nie "4.0". Po drugiej stronie (search-service, Symfony)
        // json_decode("4") daje int, nie float — cicha utrata typu w kontrakcie
        // między serwisami.
        return response()->json($projection, options: JSON_PRESERVE_ZERO_FRACTION);
    }

    /** Górna granica partii: ~500 projekcji z ofertami to rozsądny rozmiar odpowiedzi i jednego `_bulk`. */
    public const MAX_BATCH = 500;

    /**
     * `GET /api/internal/products/projections?ids=1,2,3` — droga B reindeksu
     * i nadrabianie po abort (ETAP 10, Task 12b): jedna odpowiedź na partię
     * zamiast żądania na produkt. `missing` = ID bez wiersza `products`;
     * search-service usuwa je z wersją `projected_at_us` (jak 404 w show()).
     */
    public function index(Request $request, DatabaseClock $clock, ProductProjectionBuilder $builder): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'string', 'regex:/^\d+(,\d+)*$/'],
        ]);

        $ids = array_values(array_unique(array_map('intval', explode(',', $data['ids']))));
        if (count($ids) > self::MAX_BATCH) {
            return response()->json([
                'message' => sprintf('Najwyżej %d ID w jednej partii (podano %d).', self::MAX_BATCH, count($ids)),
                'errors' => ['ids' => ['too_many']],
            ], 422);
        }

        // Jeden pomiar dla całej partii, PRZED odczytem — ta sama reguła co w show().
        $projectedAtUs = $clock->nowMicros();
        $projections = $builder->buildMany($ids, $projectedAtUs);

        return response()->json([
            'projected_at_us' => $projectedAtUs,
            'items' => array_values($projections),
            'missing' => array_values(array_diff($ids, array_keys($projections))),
        ], options: JSON_PRESERVE_ZERO_FRACTION);
    }
}
