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
    /** Timeout żądań wsadowych (partia projekcji, lista zmienionych z outboxu). */
    private const BATCH_TIMEOUT = 30.0;

    private readonly HttpClientInterface $client;

    public function __construct(
        private readonly string $baseUrl,
        private readonly TraceContext $trace,
        ?HttpClientInterface $client = null,
        string $internalApiToken = '',
    ) {
        // Timeout 3 s stosujemy ZAWSZE, także do klienta wstrzykniętego przez
        // kontener (autowiring podstawia domyślny klient frameworka z timeoutem
        // ~60 s) — inaczej wolny catalog blokowałby workera na minutę.
        //
        // Wspólny sekret /api/internal/* (catalog: RequireInternalToken) jako
        // nagłówek domyślny — trafia do każdego żądania, pojedynczego i wsadowego.
        // Pusty token = brak nagłówka; catalog odpowie wtedy 401/503, a klient
        // zgłosi ProjectionUnavailableException (głośno, z ponowieniem).
        $options = ['timeout' => 3.0];
        if ($internalApiToken !== '') {
            $options['headers'] = ['X-Internal-Token' => $internalApiToken];
        }
        $this->client = ($client ?? HttpClient::create())->withOptions($options);
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

        return $this->idPage($this->getJson('/api/internal/products/ids?'.$query, 'listy ID produktów'), 'Lista ID produktów');
    }

    /**
     * Projekcje partii produktów jednym żądaniem (ETAP 10, Task 12b) — droga B
     * i nadrabianie po abort. Jeden `projected_at_us` na partię (catalog mierzy
     * czas raz, przed odczytem), `missing` = ID bez wiersza `products`.
     *
     * @param list<int> $ids najwyżej 500 (limit catalogu)
     *
     * @return array{projected_at_us: int, items: list<array<string, mixed>>, missing: list<int>}
     *
     * @throws ProjectionUnavailableException gdy catalog nie odpowiada albo
     *                                        odpowiada w innym kształcie — nie
     *                                        zgadujemy wersji ani tego, czego brakuje
     */
    public function fetchProjections(array $ids): array
    {
        if ($ids === []) {
            return ['projected_at_us' => 0, 'items' => [], 'missing' => []];
        }

        $data = $this->getJson('/api/internal/products/projections?'.http_build_query(['ids' => implode(',', $ids)]), 'partii projekcji', self::BATCH_TIMEOUT);
        $items = $data['items'] ?? null;
        $missing = $data['missing'] ?? null;
        if (! \is_int($data['projected_at_us'] ?? null)
            || ! \is_array($items) || ! array_is_list($items)
            || array_filter($items, static fn ($item) => ! \is_array($item) || ! \is_int($item['id'] ?? null)) !== []
            || ! self::isIntList($missing)) {
            throw new ProjectionUnavailableException('Partia projekcji z catalogu w nieoczekiwanym kształcie — nie zgaduję wersji dokumentów.');
        }

        return ['projected_at_us' => $data['projected_at_us'], 'items' => $items, 'missing' => $missing];
    }

    /**
     * Strona ID produktów zmienionych od `$since` według outboxu catalogu
     * (także usuniętych) — ograniczone nadrabianie po reindex-abort.
     *
     * @return array{ids: list<int>, next_after: ?int} `next_after` null = koniec listy
     *
     * @throws ProjectionUnavailableException
     */
    public function changedProductIds(string $since, ?int $after, int $limit): array
    {
        $query = http_build_query(array_filter(['since' => $since, 'after' => $after, 'limit' => $limit], static fn ($v) => $v !== null));

        return $this->idPage($this->getJson('/api/internal/products/changed?'.$query, 'listy zmienionych produktów', self::BATCH_TIMEOUT), 'Lista zmienionych produktów');
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{ids: list<int>, next_after: ?int}
     */
    private function idPage(array $data, string $what): array
    {
        $ids = $data['ids'] ?? null;
        $next = $data['next_after'] ?? null;
        if (! self::isIntList($ids) || ! ($next === null || \is_int($next))) {
            throw new ProjectionUnavailableException("{$what} z catalogu w nieoczekiwanym kształcie.");
        }

        return ['ids' => $ids, 'next_after' => $next];
    }

    /** @phpstan-assert-if-true list<int> $value */
    private static function isIntList(mixed $value): bool
    {
        return \is_array($value) && array_is_list($value) && array_filter($value, static fn ($v) => ! \is_int($v)) === [];
    }

    /**
     * Narzędzia operatorskie (partie, outbox) dostają dłuższy timeout niż
     * consumer: 500 projekcji to ~0,1 s na pustym stosie, ale pod churnem
     * i przy dużym outboxie może być wielokrotnie więcej — a nie blokują workera.
     *
     * GET z odpowiedzią JSON; każdy błąd (4xx, 5xx, sieć, nie-JSON) to
     * ProjectionUnavailableException — operator ma ponowić, nie zgadywać.
     *
     * @return array<string, mixed>
     */
    private function getJson(string $path, string $what, float $timeout = 3.0): array
    {
        try {
            return $this->client->request('GET', rtrim($this->baseUrl, '/').$path, ['timeout' => $timeout])->toArray();
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
