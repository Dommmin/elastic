<?php

namespace App\Services\Search;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;

/**
 * Odczyt jednego dokumentu produktu z ES, tak jak go widzi wyszukiwarka
 * (alias `products-search`). `_version` dokumentu to `projected_at_us`
 * projekcji, więc ten odczyt mówi, do której chwili ES „dogonił” Postgresa.
 */
class IndexedProductReader
{
    private const ALIAS = 'products-search';

    public function __construct(private readonly Client $client) {}

    /**
     * 404 to zwykły stan (produkt jeszcze nie zaindeksowany albo usunięty),
     * więc null. Pozostałe błędy idą wyżej: to wołający decyduje, czy
     * „ES nieosiągalny” jest dla niego stanem, czy awarią.
     *
     * @return array{version: int, source: array<string, mixed>}|null
     */
    public function find(int $productId): ?array
    {
        try {
            $document = $this->client->get([
                'index' => self::ALIAS,
                'id' => (string) $productId,
            ])->asArray();
        } catch (ClientResponseException $e) {
            if ($e->getCode() === 404) {
                return null;
            }

            throw $e;
        }

        return [
            'version' => (int) $document['_version'],
            'source' => $document['_source'] ?? [],
        ];
    }
}
