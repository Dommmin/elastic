<?php

namespace App\Seller360;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Klient wewnętrznego API catalogu dla seller-360 (`/api/internal/sellers...`, `/api/internal/billing/accounts`).
 * Nagłówek `X-Internal-Token` i timeout robimy tak samo jak w `CatalogProjectionClient`.
 * Bez `final`: flusher testujemy z dublerem tej klasy.
 */
class CatalogSellerClient
{
    /** Równoległe żądania offer-stats w {@see self::offerStatsMany()}. */
    public const CONCURRENCY = 20;

    /** Strona do 5000 wierszy liczy się dłużej niż pojedyncze offer-stats. */
    private const PAGE_TIMEOUT = 30.0;

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
            return $this->client->request('GET', $this->url("/api/internal/sellers/{$sellerId}/offer-stats"))->toArray();
        } catch (ClientExceptionInterface $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Statystyki ofert wielu sprzedawców naraz: żądania lecą równolegle (HttpClient multipleksuje odpowiedzi,
     * które powstały przed pierwszym odczytem), po {@see self::CONCURRENCY} na raz. Sprzedawca bez wpisu w catalogu
     * (404) dostaje `null`; inny błąd leci wyjątkiem.
     *
     * @param  list<int>  $sellerIds
     * @return array<int, array{seller_id: int, active: int, suspended: int, sold_out: int, total: int, computed_at: string}|null>
     */
    public function offerStatsMany(array $sellerIds): array
    {
        $stats = [];
        foreach (array_chunk($sellerIds, self::CONCURRENCY) as $chunk) {
            $responses = [];
            foreach ($chunk as $id) {
                $responses[$id] = $this->client->request('GET', $this->url("/api/internal/sellers/{$id}/offer-stats"));
            }
            foreach ($responses as $id => $response) {
                try {
                    $stats[$id] = $response->toArray();
                } catch (ClientExceptionInterface $e) {
                    if ($e->getResponse()->getStatusCode() !== 404) {
                        throw $e;
                    }
                    $stats[$id] = null;
                }
            }
        }

        return $stats;
    }

    /**
     * Strona sprzedawców (keyset po `seller_id`).
     *
     * @return array{items: list<array{seller_id: int, name: ?string, city: ?string, nip: ?string, status: ?string, status_reason: ?string, synthetic: bool, version: int}>, next_after: ?int}
     */
    public function sellers(int $after, int $limit): array
    {
        return $this->client->request('GET', $this->url('/api/internal/sellers'), ['query' => ['after' => $after, 'limit' => $limit], 'timeout' => self::PAGE_TIMEOUT])->toArray();
    }

    /**
     * Strona snapshotów kont rozliczeniowych (keyset po `seller_id`).
     *
     * @return array{items: list<array{seller_id: int, balance_cents: int, overdue_cents: int, overdue_since: ?string, open_invoices: int, version: int}>, next_after: ?int}
     */
    public function billingAccounts(int $after, int $limit): array
    {
        return $this->client->request('GET', $this->url('/api/internal/billing/accounts'), ['query' => ['after' => $after, 'limit' => $limit], 'timeout' => self::PAGE_TIMEOUT])->toArray();
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }
}
