<?php

namespace App\Seller360;

use App\Seller360\Rebuild\WriteTargets;
use Elastic\Elasticsearch\Client;

/**
 * Usuwa syntetycznych sprzedawców (generator `seller360:simulate` w catalogu) z seller-360 i z danych sprzedaży.
 *
 * Kolejność:
 *  1. id syntetycznych z głównego indeksu każdej pary celów (`synthetic:true`, `search_after` po `seller_id`) —
 *     PRZED usunięciem dokumentów, bo potem nie ma ich skąd wziąć;
 *  2. `_delete_by_query` tych id w `seller-sales-daily` (`seller_id`) i w strumieniu `events-seller-default`
 *     (`seller.id`, źródło transformu). Bez tego `sales:refresh` (scripted_upsert per seller_id z wierszy
 *     sprzedaży) odtworzyłby po purge ~20 tys. dokumentów z samą sekcją `sales` i bez pola `synthetic`.
 *     Transform ciągły nie przelicza kubełków po usunięciu zdarzeń, więc wiersze nie wracają;
 *  3. `_delete_by_query synthetic:true` na każdym celu z {@see WriteTargets} (zwykle aliasy zapisu, w trakcie
 *     przebudowy także nowe indeksy; bez `WriteTargets` — `$writeIndex` i `$publicWriteIndex`).
 * Wszędzie `conflicts=proceed` (projektor może równolegle pisać sekcję — taki dokument po prostu pomijamy, a kolejny
 * przebieg go usunie) i `ignore_unavailable` (brak indeksu sprzedaży lub strumienia to 0, nie błąd).
 * Catalog nie ma uprawnień zapisu do ES, dlatego to osobna komenda po stronie search.
 */
class SyntheticPurger
{
    private const PAGE_SIZE = 5000;

    /** Paczka id w jednym `terms` (limit `index.max_terms_count` to 65 536). */
    private const TERMS_CHUNK = 10_000;

    public function __construct(
        private readonly Client $client,
        private readonly string $writeIndex = 'seller-360-write',
        private readonly string $publicWriteIndex = 'seller-360-public-write',
        private readonly ?WriteTargets $targets = null,
        private readonly string $salesIndex = 'seller-sales-daily',
        private readonly string $sellerEventsStream = 'events-seller-default',
    ) {
    }

    /** @return array{conflicts: string, refresh: bool, body: array<string, mixed>} */
    public static function request(): array
    {
        return ['conflicts' => 'proceed', 'refresh' => true, 'body' => ['query' => ['term' => ['synthetic' => true]]]];
    }

    /** @return array<string, int> indeks → liczba usuniętych dokumentów (sprzedaż, zdarzenia, potem seller-360) */
    public function purge(): array
    {
        $pairs = $this->targets?->current() ?? [['main' => $this->writeIndex, 'public' => $this->publicWriteIndex]];
        $ids = [];
        foreach ($pairs as $pair) {
            foreach ($this->syntheticIds($pair['main']) as $id) {
                $ids[$id] = true;
            }
        }
        $ids = array_map('strval', array_keys($ids));

        $deleted = [
            $this->salesIndex => $this->deleteSellers($this->salesIndex, 'seller_id', $ids),
            $this->sellerEventsStream => $this->deleteSellers($this->sellerEventsStream, 'seller.id', $ids),
        ];
        foreach ($this->targets?->indices() ?? [$this->writeIndex, $this->publicWriteIndex] as $index) {
            $response = $this->client->deleteByQuery(['index' => $index, 'ignore_unavailable' => true] + self::request())->asArray();
            $deleted[$index] = (int) ($response['deleted'] ?? 0);
        }

        return $deleted;
    }

    /** @return list<string> */
    private function syntheticIds(string $index): array
    {
        $ids = [];
        $after = null;
        do {
            $body = ['size' => self::PAGE_SIZE, '_source' => false, 'sort' => [['seller_id' => 'asc']], 'query' => ['term' => ['synthetic' => true]]];
            if ($after !== null) {
                $body['search_after'] = $after;
            }
            $hits = $this->client->search(['index' => $index, 'ignore_unavailable' => true, 'body' => $body])->asArray()['hits']['hits'] ?? [];
            foreach ($hits as $hit) {
                $ids[] = (string) $hit['sort'][0];
            }
            $after = $hits !== [] ? ($hits[array_key_last($hits)]['sort'] ?? null) : null;
        } while (\count($hits) === self::PAGE_SIZE && $after !== null);

        return $ids;
    }

    /** @param list<string> $ids */
    private function deleteSellers(string $index, string $field, array $ids): int
    {
        $deleted = 0;
        foreach (array_chunk($ids, self::TERMS_CHUNK) as $chunk) {
            $response = $this->client->deleteByQuery([
                'index' => $index,
                'ignore_unavailable' => true,
                'conflicts' => 'proceed',
                'refresh' => true,
                'body' => ['query' => ['terms' => [$field => $chunk]]],
            ])->asArray();
            $deleted += (int) ($response['deleted'] ?? 0);
        }

        return $deleted;
    }
}
