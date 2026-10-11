<?php

namespace App\Service;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * "Read-back API" po stronie search-service (docs/02-APLIKACJE.md, sekcja
 * 7.1, wariant B) — jedyny sposób, w jaki dowiadujemy się, jak wygląda
 * produkt. search-service NIE ma dostępu do bazy `catalog` (D-02).
 *
 * Bez `final`: handler testujemy jednostkowo z dublerem tej klasy, a ona
 * sama gada z siecią.
 */
class CatalogProjectionClient
{
    private readonly HttpClientInterface $client;

    public function __construct(
        private readonly string $baseUrl,
    ) {
        $this->client = HttpClient::create(['timeout' => 3.0]);
    }

    /**
     * Zarówno 200, jak i 404 niosą `projected_at_us` — wersję dokumentu w ES.
     * Odpowiedź bez tego pola (np. stary catalog w trakcie wdrożenia) to
     * ProjectionUnavailableException, czyli ponowienie: zgadnięta wersja
     * mogłaby po cichu przegrać z dokumentem albo zablokować przyszłe zapisy.
     *
     * @throws ProjectionUnavailableException gdy Laravel odpowiada 5xx/timeout
     *                                        albo bez `projected_at_us` — błąd
     *                                        przejściowy, wart ponowienia.
     */
    public function fetchProductProjection(string $productId): ProductProjection
    {
        try {
            $response = $this->client->request(
                'GET',
                rtrim($this->baseUrl, '/')."/api/internal/products/{$productId}/projection",
            );

            $data = $response->toArray();

            return new ProductProjection($data, $this->projectedAtUs($data, $productId));
        } catch (ClientExceptionInterface $e) {
            // 4xx. Jedyny spodziewany tu kod to 404 (produkt usunięty/nie
            // istnieje) — traktujemy go jako fakt biznesowy, nie awarię.
            // Wersja usunięcia pochodzi z ciała 404, więc ciało musi być JSON-em.
            if ($e->getResponse()->getStatusCode() === 404) {
                try {
                    $body = $e->getResponse()->toArray(false);
                } catch (DecodingExceptionInterface $decoding) {
                    throw new ProjectionUnavailableException(
                        "404 dla produktu {$productId} bez ciała JSON z projected_at_us",
                        previous: $decoding,
                    );
                }

                return new ProductProjection(null, $this->projectedAtUs($body, $productId));
            }

            throw new ProjectionUnavailableException(
                "Catalog API zwróciło {$e->getResponse()->getStatusCode()} dla produktu {$productId}",
                previous: $e,
            );
        } catch (HttpExceptionInterface|TransportExceptionInterface|DecodingExceptionInterface $e) {
            // 5xx, sieć/timeout albo 200 z ciałem, które nie jest JSON-em
            // — PRZEJŚCIOWE. Handler zamieni to na
            // RecoverableMessageHandlingException, Messenger ponowi.
            throw new ProjectionUnavailableException(
                "Nie udało się pobrać projekcji produktu {$productId}: {$e->getMessage()}",
                previous: $e,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function projectedAtUs(array $body, string $productId): int
    {
        if (! \is_int($body['projected_at_us'] ?? null)) {
            throw new ProjectionUnavailableException(
                "Projekcja produktu {$productId} bez całkowitego projected_at_us — nie zgaduję wersji dokumentu.",
            );
        }

        return $body['projected_at_us'];
    }
}
