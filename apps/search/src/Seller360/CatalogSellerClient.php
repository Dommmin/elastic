<?php

namespace App\Seller360;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Klient wewnętrznego API catalogu dla seller-360 (`/api/internal/sellers/{id}/...`).
 * Nagłówek `X-Internal-Token` i timeout robimy tak samo jak w `CatalogProjectionClient`.
 * Bez `final`: flusher testujemy z dublerem tej klasy.
 */
class CatalogSellerClient
{
    private readonly HttpClientInterface $client;

    public function __construct(
        private readonly string $baseUrl,
        ?HttpClientInterface $client = null,
        string $internalApiToken = '',
    ) {
        $options = ['timeout' => 3.0];
        if ($internalApiToken !== '') {
            $options['headers'] = ['X-Internal-Token' => $internalApiToken];
        }
        $this->client = ($client ?? HttpClient::create())->withOptions($options);
    }

    /**
     * @return array{seller_id: int, active: int, suspended: int, sold_out: int, total: int, computed_at: string}|null
     *                                                                                                                  `null` gdy sprzedawcy nie ma (404); inne błędy lecą wyjątkiem
     */
    public function offerStats(int $sellerId): ?array
    {
        try {
            return $this->client->request('GET', rtrim($this->baseUrl, '/')."/api/internal/sellers/{$sellerId}/offer-stats")->toArray();
        } catch (ClientExceptionInterface $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                return null;
            }
            throw $e;
        }
    }
}
