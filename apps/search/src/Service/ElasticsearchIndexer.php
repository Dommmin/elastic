<?php

namespace App\Service;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;

/**
 * Jedyne miejsce w search-service, które pisze do Elasticsearcha — zbiera
 * cały kod stykający się z external versioning w jednym punkcie
 * (docs/05-SPOJNOSC-DANYCH.md, sekcja 3, [5]).
 *
 * ZNANE OGRANICZENIE (znalezione przy pierwszym realnym uruchomieniu,
 * ETAP 6, nie naprawione — świadomie odłożone, opisuję żeby nie zaskoczyło
 * po cichu): `sequence` przekazywane do `version` to LOKALNY licznik
 * agregatu źródłowego (Product.version, Offer.version — każdy zaczyna od 1
 * niezależnie), a jeden dokument ES bywa budowany z KILKU agregatów
 * (product.* ORAZ offer.* piszą do tego samego `_id`). Dwa różne agregaty
 * mogą mieć ten sam numer sekwencji — ES odrzuci drugi zapis jako "stale"
 * (409), NIEZALEŻNIE od tego, czy faktycznie jest przestarzały. W praktyce
 * zwykle nie szkodzi, bo projekcja (CatalogProjectionClient) zawsze ciągnie
 * PEŁNY aktualny stan produktu — pierwszy zapis i tak zawiera świeże dane
 * z obu źródeł. Ale to jest kruche: kolejna, PRAWDZIWA zmiana oferty na
 * niskim numerze sekwencji może zostać błędnie odrzucona jako nieaktualna.
 * Właściwa naprawa (poza zakresem ETAPU 6): jeden monotoniczny licznik NA
 * DOKUMENT, utrzymywany przez search-service (nie kopiowany 1:1 z Laravela),
 * albo porównanie po `occurred_at` zamiast surowego numeru wersji źródła.
 */
final class ElasticsearchIndexer
{
    /**
     * Alias, NIE nazwa fizycznego indeksu (docs/06-PLAN-WDROZENIA.md, D-11 —
     * aliasy od dnia 1). Reindeks w ETAPIE 10 przełączy ten alias na nowy
     * indeks atomowo; ten kod nigdy nie dowie się, że to się stało.
     */
    private const ALIAS = 'products-search';

    public function __construct(
        private readonly Client $client,
    ) {
    }

    /**
     * @param  array<string, mixed>  $projection  odpowiedź z GET /api/internal/products/{id}/projection
     *
     * @return IndexOutcome::Indexed — dokument zapisany
     *                     ::Stale   — 409, ktoś już zaindeksował nowszą wersję
     *                                 tego samego produktu. TO JEST SUKCES,
     *                                 nie błąd — patrz docs/05, sekcja 3, [5]:
     *                                 "409 to dowód, że mechanizm zadziałał".
     */
    public function indexProduct(array $projection, int $sequence): IndexOutcome
    {
        $productId = (string) $projection['id'];
        $document = $this->toEsDocument($projection);

        try {
            $this->client->index([
                'index' => self::ALIAS,
                'id' => $productId,
                'body' => $document,
                'version' => $sequence,
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
    public function deleteProduct(string $productId, int $sequence): IndexOutcome
    {
        try {
            $this->client->delete([
                'index' => self::ALIAS,
                'id' => $productId,
                'version' => $sequence,
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
     * ŚWIADOME, nie przypadek. `id` i `version` NIE trafiają do `_source`:
     * `id` staje się `_id` dokumentu, `version` parametrem zapytania.
     *
     * @param  array<string, mixed>  $projection
     * @return array<string, mixed>
     */
    private function toEsDocument(array $projection): array
    {
        $document = $projection;
        unset($document['id'], $document['version']);

        return $document;
    }
}
