<?php

namespace App\Service;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;

/**
 * Jedyne miejsce w search-service, które pisze do Elasticsearcha — zbiera
 * cały kod stykający się z external versioning w jednym punkcie
 * (docs/05-SPOJNOSC-DANYCH.md, sekcja 3, [5]).
 *
 * WERSJA DOKUMENTU = CZAS ODCZYTU PROJEKCJI (`projected_at_us`, µs z zegara
 * Postgresa catalogu, mierzony PRZED odczytem danych produktu). Wcześniej
 * wersją był `sequence` zdarzenia — lokalny licznik agregatu źródłowego
 * (Product.version, Offer.version, każdy od 1). Jeden dokument ES powstaje
 * z KILKU agregatów, więc numery się zderzały: zmiana drugiej oferty
 * z niskim `sequence` dostawała 409 i ginęła po cichu jako "nieaktualna".
 * Projekcja zawsze niesie PEŁNY stan produktu, więc o świeżości dokumentu
 * decyduje to, KIEDY catalog go odczytał, a nie który agregat się zmienił:
 * późniejszy odczyt widzi co najmniej to samo, co wcześniejszy (READ
 * COMMITTED), a jeden zegar porządkuje zapisy ze wszystkich agregatów.
 * Szczegóły i uzasadnienie kolejności pomiaru:
 * docs/superpowers/specs/2026-10-11-panel-edycji-design.md, część 1.
 *
 * Bez `final`: handler testujemy jednostkowo z dublerem tej klasy.
 */
class ElasticsearchIndexer
{
    /** Ile powodów błędów pozycji `_bulk` zwracać z bulkSync (reszta tylko w liczniku). */
    public const MAX_ERROR_SAMPLES = 5;

    /**
     * @param  string  $alias  alias ZAPISU (`products-write`), NIE nazwa fizycznego
     *                         indeksu (docs/06-PLAN-WDROZENIA.md, D-11). Odczyt
     *                         idzie osobnym `products-search`, więc reindeks
     *                         w ETAPIE 10 może przepiąć zapis na nowy indeks,
     *                         zanim odczyt dostanie go w całości; ten kod nigdy
     *                         nie dowie się, że to się stało. Parametr także dla
     *                         testów, które piszą do jednorazowego indeksu.
     */
    public function __construct(
        private readonly Client $client,
        private readonly string $alias = IndexAliases::WRITE,
    ) {
    }

    /**
     * @param  array<string, mixed>  $projection  odpowiedź z GET /api/internal/products/{id}/projection
     * @param  int  $docVersion  `projected_at_us` tej projekcji, nie `sequence` zdarzenia
     *
     * @return IndexOutcome::Indexed — dokument zapisany
     *                     ::Stale   — 409, ktoś już zaindeksował nowszą wersję
     *                                 tego samego produktu. TO JEST SUKCES,
     *                                 nie błąd — patrz docs/05, sekcja 3, [5]:
     *                                 "409 to dowód, że mechanizm zadziałał".
     */
    public function indexProduct(array $projection, int $docVersion): IndexOutcome
    {
        $productId = (string) $projection['id'];
        $document = $this->toEsDocument($projection);

        try {
            $this->client->index([
                'index' => $this->alias,
                'id' => $productId,
                'body' => $document,
                'version' => $docVersion,
                'version_type' => 'external',
            ]);

            return IndexOutcome::Indexed;
        } catch (ClientResponseException $e) {
            if ($e->getCode() === 409) {
                return IndexOutcome::Stale;
            }

            throw $e;
        }
    }

    /**
     * product.deleted — też wersjonowane, z tego samego powodu co index():
     * usunięcie, które dotarło "za wcześnie" (przed nowszym update'em tego
     * samego produktu z powodu przetasowania kolejności w kolejce), nie
     * może wygrać z nowszym stanem.
     */
    public function deleteProduct(string $productId, int $docVersion): IndexOutcome
    {
        try {
            $this->client->delete([
                'index' => $this->alias,
                'id' => $productId,
                'version' => $docVersion,
                'version_type' => 'external',
            ]);

            return IndexOutcome::Indexed;
        } catch (ClientResponseException $e) {
            // 404 = już nie istnieje (poprzednie delete albo nigdy nie
            // zaindeksowany), 409 = jest nowsza wersja — oba to NIE błąd,
            // ale to różne fakty, więc liczymy je osobno.
            return match ($e->getCode()) {
                404 => IndexOutcome::NotFound,
                409 => IndexOutcome::Stale,
                default => throw $e,
            };
        }
    }

    /**
     * Partia jednym `_bulk` (ETAP 10, Task 12b: droga B i nadrabianie po abort)
     * — ta sama decyzja co indexProduct/deleteProduct, tylko bez żądania na
     * produkt: `index` dla projekcji, `delete` dla `missing`, wszystko
     * z `version_type: external` i wersją = `projected_at_us` partii.
     *
     * Wynik pozycji: 409 = stale (nowsza wersja już jest — sukces, nie błąd),
     * 404 przy delete = not_found (ES i tak zostawia tombstone z tą wersją,
     * więc spóźniona starsza projekcja nie wskrzesi produktu — Task 5),
     * każdy inny błąd pozycji = errors (pierwsze MAX_ERROR_SAMPLES powodów
     * w `error_samples`). Błąd całego żądania (sieć, 5xx) leci wyjątkiem,
     * odpowiedź z inną liczbą pozycji niż akcji — UnexpectedValueException.
     *
     * @param list<array<string, mixed>> $items   projekcje z catalogu
     * @param list<int>                  $missing ID bez produktu w catalogu
     * @param int                        $version `projected_at_us` partii
     *
     * @return array{indexed: int, stale: int, deleted: int, not_found: int, errors: int, error_samples: list<string>}
     */
    public function bulkSync(array $items, array $missing, int $version): array
    {
        $result = ['indexed' => 0, 'stale' => 0, 'deleted' => 0, 'not_found' => 0, 'errors' => 0, 'error_samples' => []];
        if ($items === [] && $missing === []) {
            return $result;
        }

        $body = [];
        foreach ($items as $projection) {
            $body[] = ['index' => ['_index' => $this->alias, '_id' => (string) $projection['id'], 'version' => $version, 'version_type' => 'external']];
            $body[] = $this->toEsDocument($projection);
        }
        foreach ($missing as $productId) {
            $body[] = ['delete' => ['_index' => $this->alias, '_id' => (string) $productId, 'version' => $version, 'version_type' => 'external']];
        }

        $response = $this->client->bulk(['body' => $body])->asArray();

        // Każda akcja musi mieć swoją pozycję w odpowiedzi. Krótsza lista dałaby
        // zaniżone liczniki z 0 błędów — backfill postawiłby pieczęć mimo braków.
        $expected = \count($items) + \count($missing);
        $entries = $response['items'] ?? null;
        if (! \is_array($entries) || \count($entries) !== $expected) {
            throw new \UnexpectedValueException(\sprintf('_bulk: wysłano %d akcji, odpowiedź ma %s pozycji.', $expected, \is_array($entries) ? \count($entries) : 'brak'));
        }

        foreach ($entries as $entry) {
            $action = array_key_first($entry);
            $item = $entry[$action];
            $status = (int) ($item['status'] ?? 0);

            $key = match (true) {
                $status === 409 => 'stale',
                $action === 'delete' && $status === 404 => 'not_found',
                $action === 'delete' && $status >= 200 && $status < 300 => 'deleted',
                $action === 'index' && $status >= 200 && $status < 300 => 'indexed',
                default => 'errors',
            };
            ++$result[$key];

            if ($key === 'errors' && \count($result['error_samples']) < self::MAX_ERROR_SAMPLES) {
                $result['error_samples'][] = sprintf('%s: %s %s', $item['_id'] ?? '?', $item['error']['type'] ?? "HTTP {$status}", $item['error']['reason'] ?? '');
            }
        }

        return $result;
    }

    /**
     * Projekcja z Laravela ma dokładnie te same nazwy pól co `_source`
     * w mapowaniu (infra/elasticsearch/mappings/products-v1.json) — to
     * ŚWIADOME, nie przypadek. `id`, `version` i `projected_at_us` NIE trafiają
     * do `_source` (mapowanie jest `dynamic: strict`): `id` staje się `_id`
     * dokumentu, `projected_at_us` jego wersją, a `version` (licznik agregatu
     * produktu) nie jest tu do niczego potrzebny.
     *
     * @param  array<string, mixed>  $projection
     * @return array<string, mixed>
     */
    private function toEsDocument(array $projection): array
    {
        $document = $projection;
        unset($document['id'], $document['version'], $document['projected_at_us']);

        return $document;
    }
}
