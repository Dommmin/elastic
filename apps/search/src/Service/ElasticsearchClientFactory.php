<?php

namespace App\Service;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;

/**
 * search-service łączy się na konto `searchsvc` (write + manage) —
 * przeciwieństwo `catalog`, które ma tylko odczyt. Patrz
 * infra/elasticsearch/setup-security.sh, rola `search_service`, i D-03
 * w docs/06-PLAN-WDROZENIA.md ("tylko search-service pisze do ES").
 */
final class ElasticsearchClientFactory
{
    public function __construct(
        private readonly string $host,
        private readonly string $user,
        private readonly string $password,
    ) {
    }

    public function create(): Client
    {
        return ClientBuilder::create()
            ->setHosts([$this->host])
            ->setBasicAuthentication($this->user, $this->password)
            ->build();
    }
}
