<?php

namespace App\Security\Simulation;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;

/**
 * Popularność z Elasticsearcha kontem `catalog_app` (tylko odczyt; uprawnienia do `product-popularity*`
 * i `security-rules` dodane w ETAPIE 15, infra/elasticsearch/setup-security.sh).
 */
final class ElasticsearchPopularitySource implements PopularitySource
{
    private const RULES_INDEX = 'security-rules';

    private const HUG_RULE = 'price-hug-threshold';

    private const POPULARITY_INDEX = 'product-popularity';

    public function __construct(private readonly Client $client) {}

    public function p95(): ?float
    {
        try {
            $source = $this->client->get(['index' => self::RULES_INDEX, 'id' => self::HUG_RULE])->asArray()['_source'] ?? [];
        } catch (ClientResponseException $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            throw $e;
        }
        $p95 = $source['params']['p95'] ?? null;

        return is_numeric($p95) ? (float) $p95 : null;
    }

    public function productsAtLeast(float $min, int $limit): array
    {
        return $this->products(['gte' => $min], $limit);
    }

    public function productsBelow(float $max, int $limit): array
    {
        return $this->products(['lt' => $max], $limit);
    }

    /**
     * @param  array<string, float>  $range
     * @return list<int>
     */
    private function products(array $range, int $limit): array
    {
        $hits = $this->client->search(['index' => self::POPULARITY_INDEX, 'body' => [
            'size' => $limit,
            '_source' => ['product_id'],
            'query' => ['range' => ['popularity' => $range]],
            'sort' => [['product_id' => 'asc']],
        ]])->asArray()['hits']['hits'] ?? [];

        $ids = array_map(static fn (array $hit): int => (int) $hit['_source']['product_id'], $hits);
        sort($ids);

        return $ids;
    }
}
