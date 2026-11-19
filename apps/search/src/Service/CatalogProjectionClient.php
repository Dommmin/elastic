<?php

namespace App\Service;

use App\Tracing\TraceContext;
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
        private readonly TraceContext $trace,
        ?HttpClientInterface $client = null,
    ) {
        // Timeout 3 s stosujemy ZAWSZE, także do klienta wstrzykniętego przez
        // kontener (autowiring podstawia domyślny klient frameworka z timeoutem
        // ~60 s) — inaczej wolny catalog blokowałby workera na minutę.
        $this->client = ($client ?? HttpClient::create())->withOptions(['timeout' => 3.0]);
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
        // W3C traceparent: catalog dołącza swoje logi do śladu zdarzenia, które
        // wywołało tę projekcję. Span-id jest losowy — nie raportujemy spanów,
        // catalog używa tylko trace-id.
        $headers = [];
        $traceId = $this->trace->get();
        if ($traceId !== null) {
            $headers['traceparent'] = '00-'.$traceId.'-'.bin2hex(random_bytes(8)).'-01';
        }

        try {
            $response = $this->client->request(
                'GET',
                rtrim($this->baseUrl, '/')."/api/internal/products/{$productId}/projection",
                ['headers' => $headers],
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
     * Liczba wierszy `products` w catalogu — źródło prawdy dla bramki
     * jakości przed przełączeniem odczytu (ETAP 10, spec 5.5).
     *
     * @throws ProjectionUnavailableException gdy catalog nie odpowiada albo
     *                                        odpowiada bez całkowitego `count`
     */
    public function countProducts(): int
    {
        $data = $this->getJson('/api/internal/products/count', 'licznika produktów');
        if (! \is_int($data['count'] ?? null)) {
            throw new ProjectionUnavailableException('Licznik produktów z catalogu bez całkowitego count.');
        }

        return $data['count'];
    }

    /**
     * Strona ID produktów po kursorze (`id > after`, rosnąco) — droga B
     * reindeksu (backfill z catalogu) i przegląd zbieżności.
     *
     * @return array{ids: list<int>, next_after: ?int} `next_after` null = koniec listy
     *
     * @throws ProjectionUnavailableException gdy catalog nie odpowiada albo
     *                                        odpowiada w innym kształcie
     */
    public function productIds(?int $after, int $limit): array
    {
        $query = http_build_query(array_filter(['after' => $after, 'limit' => $limit], static fn ($v) => $v !== null));
        $data = $this->getJson('/api/internal/products/ids?'.$query, 'listy ID produktów');

        $ids = $data['ids'] ?? null;
        $next = $data['next_after'] ?? null;
        if (! \is_array($ids) || ! array_is_list($ids) || array_filter($ids, static fn ($id) => ! \is_int($id)) !== []
            || ! ($next === null || \is_int($next))) {
            throw new ProjectionUnavailableException('Lista ID produktów z catalogu w nieoczekiwanym kształcie.');
        }

        return ['ids' => $ids, 'next_after' => $next];
    }

    /**
     * GET z odpowiedzią JSON; każdy błąd (4xx, 5xx, sieć, nie-JSON) to
     * ProjectionUnavailableException — operator ma ponowić, nie zgadywać.
     *
     * @return array<string, mixed>
     */
    private function getJson(string $path, string $what): array
    {
        try {
            return $this->client->request('GET', rtrim($this->baseUrl, '/').$path)->toArray();
        } catch (HttpExceptionInterface|TransportExceptionInterface|DecodingExceptionInterface $e) {
            throw new ProjectionUnavailableException("Nie udało się pobrać {$what} z catalogu: {$e->getMessage()}", previous: $e);
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
