<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Services\Search\ProductProjectionBuilder;
use App\Support\DatabaseClock;
use Illuminate\Http\JsonResponse;

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
}
