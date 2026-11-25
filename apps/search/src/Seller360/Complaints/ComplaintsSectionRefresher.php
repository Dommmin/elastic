<?php

namespace App\Seller360\Complaints;

use App\Seller360\SectionMeta;
use App\Seller360\SectionWriter;
use Elastic\Elasticsearch\Client;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Przeliczenie sekcji `complaints` w seller-360 z indeksu `complaints` (ETAP 13).
 *
 * Wywołuje je import (także `--retry-unmatched`) dla sprzedawców, których wiersze właśnie zapisał;
 * nie ma przeliczenia okresowego. Jedno zapytanie na paczkę sprzedawców: agregacja `terms` po `seller_id`
 * z podagregacjami:
 *  - `open`: status z {@see self::OPEN_STATUSES};
 *  - `last_30d`: `created_at >= now-30d`;
 *  - `top_categories`: 3 najczęstsze kategorie ze WSZYSTKICH reklamacji sprzedawcy;
 *  - `recent_texts`: `body` 50 najnowszych (top_hits po `created_at` malejąco).
 * Wersja sekcji to `now` w ms, więc strażnik wersji w SectionWriter przepuszcza każde kolejne przeliczenie.
 */
class ComplaintsSectionRefresher
{
    /** Statusy zgłoszenia uznawane za otwarte (importer zapisuje status małymi literami). */
    public const OPEN_STATUSES = ['open', 'new', 'in_progress'];

    public const RECENT_TEXTS = 50;
    public const TOP_CATEGORIES = 3;

    /** Sprzedawców na jedno zapytanie: top_hits trzyma do 50 treści na każdego. */
    private const CHUNK = 100;

    public function __construct(
        private readonly Client $client,
        private readonly SectionWriter $writer,
        private readonly LoggerInterface $logger = new NullLogger,
        private readonly string $index = 'complaints',
    ) {
    }

    /**
     * @param  list<int>  $sellerIds
     * @param  SectionWriter|null  $writer  inny cel zapisu (backfill przebudowy kieruje go na nowe indeksy)
     * @param  bool  $zeroMissing  sprzedawcy bez żadnej reklamacji dostają pustą sekcję (zera, puste listy) —
     *                             po usunięciu reklamacji (`security:purge-synthetic`, ETAP 15) stara sekcja nie zostaje
     * @return int liczba zapisanych sekcji (`created|updated`)
     */
    public function refreshSellers(array $sellerIds, ?\DateTimeImmutable $now = null, ?SectionWriter $writer = null, bool $zeroMissing = false): int
    {
        $writer ??= $this->writer;
        $sellerIds = array_values(array_unique($sellerIds));
        if ($sellerIds === []) {
            return 0;
        }
        $now = ($now ?? new \DateTimeImmutable)->setTimezone(new \DateTimeZone('UTC'));
        $version = (int) $now->format('Uv');

        // Świeżo zaindeksowane wiersze muszą być widoczne dla agregacji.
        $this->client->indices()->refresh(['index' => $this->index]);

        $written = 0;
        foreach (array_chunk($sellerIds, self::CHUNK) as $chunk) {
            $items = [];
            $sections = $this->aggregate($chunk, $now);
            if ($zeroMissing) {
                foreach ($chunk as $sellerId) {
                    $sections[$sellerId] ??= ['open' => 0, 'last_30d' => 0, 'top_categories' => [], 'recent_texts' => []];
                }
            }
            foreach ($sections as $sellerId => $section) {
                $items[] = [
                    'sellerId' => $sellerId,
                    'section' => 'complaints',
                    'data' => $section,
                    'meta' => new SectionMeta($version, $now),
                    'docFields' => [],
                ];
            }
            foreach ($writer->applyMany($items) as $result) {
                if ($result === 'created' || $result === 'updated') {
                    $written++;
                } elseif ($result === 'error') {
                    $this->logger->warning('seller-360: zapis sekcji complaints odrzucony przez ES.');
                }
            }
        }

        return $written;
    }

    /**
     * @param  list<int>  $chunk
     * @return array<int, array<string, mixed>> sekcja per seller_id (tylko ci, którzy mają reklamacje)
     */
    private function aggregate(array $chunk, \DateTimeImmutable $now): array
    {
        $response = $this->client->search([
            'index' => $this->index,
            'body' => [
                'size' => 0,
                'query' => ['terms' => ['seller_id' => $chunk]],
                'aggs' => ['sellers' => [
                    'terms' => ['field' => 'seller_id', 'size' => \count($chunk)],
                    'aggs' => [
                        'open' => ['filter' => ['terms' => ['status' => self::OPEN_STATUSES]]],
                        'last_30d' => ['filter' => ['range' => ['created_at' => ['gte' => $now->modify('-30 days')->format('Y-m-d\TH:i:s\Z')]]]],
                        'categories' => ['terms' => ['field' => 'category', 'size' => self::TOP_CATEGORIES]],
                        'recent' => ['top_hits' => ['size' => self::RECENT_TEXTS, 'sort' => [['created_at' => 'desc']], '_source' => ['body']]],
                    ],
                ]],
            ],
        ])->asArray();

        $sections = [];
        foreach ($response['aggregations']['sellers']['buckets'] ?? [] as $bucket) {
            $texts = [];
            foreach ($bucket['recent']['hits']['hits'] as $hit) {
                $body = $hit['_source']['body'] ?? null;
                if (\is_string($body) && $body !== '') {
                    $texts[] = $body;
                }
            }
            $sections[(int) $bucket['key']] = [
                'open' => (int) $bucket['open']['doc_count'],
                'last_30d' => (int) $bucket['last_30d']['doc_count'],
                'top_categories' => array_map(static fn (array $b): string => (string) $b['key'], $bucket['categories']['buckets']),
                'recent_texts' => $texts,
            ];
        }

        return $sections;
    }
}
