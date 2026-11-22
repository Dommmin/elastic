<?php

namespace App\Seller360;

use App\Seller360\Rebuild\WriteTargets;
use Elastic\Elasticsearch\Client;

/**
 * Usuwa syntetycznych sprzedawców (generator `seller360:simulate` w catalogu) z obu indeksów
 * seller-360: `_delete_by_query synthetic:true` na każdym celu z {@see WriteTargets} (zwykle aliasy zapisu,
 * w trakcie przebudowy także nowe indeksy; bez `WriteTargets` — `$writeIndex` i `$publicWriteIndex`), `conflicts=proceed` (projektor
 * może równolegle pisać sekcję — taki dokument po prostu pomijamy, a kolejny przebieg go usunie).
 * Catalog nie ma uprawnień zapisu do ES, dlatego to osobna komenda po stronie search.
 */
class SyntheticPurger
{
    public function __construct(
        private readonly Client $client,
        private readonly string $writeIndex = 'seller-360-write',
        private readonly string $publicWriteIndex = 'seller-360-public-write',
        private readonly ?WriteTargets $targets = null,
    ) {
    }

    /** @return array{conflicts: string, refresh: bool, body: array<string, mixed>} */
    public static function request(): array
    {
        return ['conflicts' => 'proceed', 'refresh' => true, 'body' => ['query' => ['term' => ['synthetic' => true]]]];
    }

    /** @return array<string, int> indeks → liczba usuniętych dokumentów */
    public function purge(): array
    {
        $deleted = [];
        foreach ($this->targets?->indices() ?? [$this->writeIndex, $this->publicWriteIndex] as $index) {
            $response = $this->client->deleteByQuery(['index' => $index] + self::request())->asArray();
            $deleted[$index] = (int) ($response['deleted'] ?? 0);
        }

        return $deleted;
    }
}
