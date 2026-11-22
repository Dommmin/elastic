<?php

namespace App\Seller360;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;

/**
 * Odczyt pełnego dokumentu `seller-360` dla konsultanta (ETAP 13). Klient to singleton
 * `Client` catalogu (konto `catalog`, rola `catalog_app` z `read` na `seller-360*`).
 * Błędy transportu idą wyżej: to wołający decyduje, czy „ES nieosiągalny” jest stanem strony.
 */
class Seller360Reader
{
    public const ALIAS = 'seller-360';

    public function __construct(private readonly Client $client) {}

    /**
     * @param  array<string, mixed>  $body
     * @return array{took: int, total: int, hits: list<array{id: string, source: array<string, mixed>}>}
     */
    public function search(array $body): array
    {
        $response = $this->client->search(['index' => self::ALIAS, 'body' => $body])->asArray();

        $hits = [];
        foreach ($response['hits']['hits'] ?? [] as $hit) {
            $hits[] = ['id' => (string) $hit['_id'], 'source' => $hit['_source'] ?? []];
        }

        return [
            'took' => (int) ($response['took'] ?? 0),
            'total' => (int) ($response['hits']['total']['value'] ?? count($hits)),
            'hits' => $hits,
        ];
    }

    /**
     * 404 to zwykły stan (sprzedawca bez dokumentu), więc null.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $sellerId): ?array
    {
        try {
            $document = $this->client->get(['index' => self::ALIAS, 'id' => (string) $sellerId])->asArray();
        } catch (ClientResponseException $e) {
            if ($e->getCode() === 404) {
                return null;
            }

            throw $e;
        }

        return $document['_source'] ?? [];
    }
}
