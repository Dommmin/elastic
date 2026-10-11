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
    /**
     * @param  string  $alias  alias, NIE nazwa fizycznego indeksu
     *                         (docs/06-PLAN-WDROZENIA.md, D-11 — aliasy od dnia 1).
     *                         Reindeks w ETAPIE 10 przełączy ten alias na nowy
     *                         indeks atomowo; ten kod nigdy nie dowie się, że to
     *                         się stało. Parametr tylko dla testu integracyjnego,
     *                         który pisze do jednorazowego indeksu.
     */
    public function __construct(
        private readonly Client $client,
        private readonly string $alias = 'products-search',
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
            // zaindeksowany) — to również nie jest błąd.
            if (in_array($e->getCode(), [404, 409], true)) {
                return IndexOutcome::Stale;
            }

            throw $e;
        }
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
