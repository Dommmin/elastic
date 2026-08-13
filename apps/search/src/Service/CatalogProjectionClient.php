<?php

namespace App\Service;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * "Read-back API" po stronie search-service (docs/02-APLIKACJE.md, sekcja
 * 7.1, wariant B) — jedyny sposób, w jaki dowiadujemy się, jak wygląda
 * produkt. search-service NIE ma dostępu do bazy `catalog` (D-02).
 */
final class CatalogProjectionClient
{
    private readonly HttpClientInterface $client;

    public function __construct(
        private readonly string $baseUrl,
    ) {
        $this->client = HttpClient::create(['timeout' => 3.0]);
    }

    /**
     * @return array<string, mixed>|null null = produkt nie istnieje (404) —
     *                                    aktualny stan, nie błąd do ponawiania.
     *
     * @throws ProjectionUnavailableException gdy Laravel odpowiada 5xx/timeout
     *                                        — TO jest błąd przejściowy, wart ponowienia.
     */
    public function fetchProductProjection(string $productId): ?array
    {
        try {
            $response = $this->client->request(
                'GET',
                rtrim($this->baseUrl, '/')."/api/internal/products/{$productId}/projection",
            );

            return $response->toArray();
        } catch (ClientExceptionInterface $e) {
            // 4xx. Jedyny spodziewany tu kod to 404 (produkt usunięty/nie
            // istnieje) — traktujemy go jako fakt biznesowy, nie awarię.
            if ($e->getResponse()->getStatusCode() === 404) {
                return null;
            }

            throw new ProjectionUnavailableException(
                "Catalog API zwróciło {$e->getResponse()->getStatusCode()} dla produktu {$productId}",
                previous: $e,
            );
        } catch (HttpExceptionInterface|TransportExceptionInterface $e) {
            // 5xx albo sieć/timeout — PRZEJŚCIOWE. Handler zamieni to na
            // RecoverableMessageHandlingException, Messenger ponowi.
            throw new ProjectionUnavailableException(
                "Nie udało się pobrać projekcji produktu {$productId}: {$e->getMessage()}",
                previous: $e,
            );
        }
    }
}
