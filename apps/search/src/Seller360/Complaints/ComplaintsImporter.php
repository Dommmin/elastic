<?php

namespace App\Seller360\Complaints;

use Elastic\Elasticsearch\Client;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Import reklamacji z CSV (ETAP 13): `ticket_id,nip,created_at,category,status,body`, UTF-8, RFC 4180.
 *
 * NIP → sprzedawca rozwiązuje pipeline `complaints-resolve-seller` (domyślny pipeline indeksu `complaints`):
 * enrich `seller-by-nip` dopisuje `seller_id` i `region`, a wiersz bez dopasowania procesor `reroute`
 * przekierowuje do `complaints-unmatched`. Dlatego importer zawsze pisze do `complaints`, a o miejscu
 * lądowania mówi `_index` w odpowiedzi bulk. Indeks enrich jest migawką seller-360, więc polityka
 * jest wykonywana (`_execute`) na początku każdego importu i ponowienia.
 *
 * `_id = ticket_id`: powtórny import tego samego pliku nadpisuje dokumenty, nie dubluje ich.
 * Wiersz, który po powtórnym imporcie zyskał dopasowanie, jest usuwany z `complaints-unmatched`.
 * Po zapisie importer przelicza sekcję `complaints` dotkniętych sprzedawców (rozstrzygnięcie R4).
 */
class ComplaintsImporter
{
    public const HEADER = ['ticket_id', 'nip', 'created_at', 'category', 'status', 'body'];

    private const POLICY = 'seller-by-nip';
    private const PIPELINE = 'complaints-resolve-seller';
    private const BATCH = 1000;

    public function __construct(
        private readonly Client $client,
        private readonly ComplaintsSectionRefresher $refresher,
        private readonly LoggerInterface $logger = new NullLogger,
        private readonly string $index = 'complaints',
        private readonly string $unmatchedIndex = 'complaints-unmatched',
    ) {
    }

    /** @throws \InvalidArgumentException plik nieczytelny albo zły nagłówek */
    public function import(string $path): ImportResult
    {
        $rows = $this->rows($path);
        $this->executePolicy();

        $indexed = $unmatched = $failed = 0;
        $sellerIds = [];
        $batch = [];
        foreach ($rows as $row) {
            if ($row === null) {
                $failed++;

                continue;
            }
            $batch[] = $row;
            if (\count($batch) >= self::BATCH) {
                $this->flush($batch, $indexed, $unmatched, $failed, $sellerIds);
                $batch = [];
            }
        }
        $this->flush($batch, $indexed, $unmatched, $failed, $sellerIds);

        $sections = $this->refresher->refreshSellers(array_keys($sellerIds));

        return new ImportResult($indexed, $unmatched, $failed, $sections);
    }

    /**
     * `--retry-unmatched`: wiersze z `complaints-unmatched` przechodzą ponownie przez pipeline
     * (`_reindex` do `complaints`). Te, które dostały dopasowanie, są usuwane z kolejki; reszta zostaje
     * (reroute odkłada je w to samo miejsce). `indexed` to wiersze przeniesione, `unmatched` pozostałe.
     */
    public function retryUnmatched(): ImportResult
    {
        $this->executePolicy();
        $this->client->indices()->refresh(['index' => $this->unmatchedIndex]);
        $ids = $this->unmatchedIds();
        if ($ids === []) {
            return new ImportResult;
        }

        $reindex = $this->client->reindex([
            'wait_for_completion' => true,
            'refresh' => true,
            'body' => [
                'conflicts' => 'proceed',
                'source' => ['index' => $this->unmatchedIndex],
                'dest' => ['index' => $this->index, 'pipeline' => self::PIPELINE],
            ],
        ])->asArray();
        $failed = \count($reindex['failures'] ?? []);

        $moved = [];
        $sellerIds = [];
        $this->client->indices()->refresh(['index' => $this->index]);
        foreach (array_chunk($ids, self::BATCH) as $chunk) {
            foreach ($this->resolved($chunk) as $id => $sellerId) {
                $moved[] = (string) $id;
                $sellerIds[$sellerId] = true;
            }
        }
        foreach (array_chunk($moved, self::BATCH) as $chunk) {
            $this->deleteFromUnmatched($chunk);
        }

        $sections = $this->refresher->refreshSellers(array_keys($sellerIds));

        return new ImportResult(\count($moved), \count($ids) - \count($moved), $failed, $sections);
    }

    /**
     * Generator wierszy gotowych do indeksowania; `null` oznacza wiersz odrzucony (zła liczba pól,
     * pusty ticket_id/nip, nieparsowalna data). Nagłówek sprawdzamy od razu przy tworzeniu generatora.
     *
     * @return \Generator<int, array{id: string, doc: array<string, string>}|null>
     */
    private function rows(string $path): \Generator
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new \InvalidArgumentException("Nie mogę odczytać pliku CSV: {$path}");
        }
        $file = new \SplFileObject($path, 'r');
        $file->setFlags(\SplFileObject::READ_CSV | \SplFileObject::READ_AHEAD | \SplFileObject::SKIP_EMPTY | \SplFileObject::DROP_NEW_LINE);
        // Pusty `escape` = czysty RFC 4180 (cudzysłów podwajamy, backslash nie jest znakiem specjalnym).
        $file->setCsvControl(',', '"', '');

        $header = $file->current();
        $file->next();
        if (\is_array($header) && isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        }
        if ($header !== self::HEADER) {
            throw new \InvalidArgumentException('Zły nagłówek CSV, oczekiwano: '.implode(',', self::HEADER));
        }

        return $this->parse($file);
    }

    /** @return \Generator<int, array{id: string, doc: array<string, string>}|null> */
    private function parse(\SplFileObject $file): \Generator
    {
        for (; $file->valid(); $file->next()) {
            $fields = $file->current();
            if (! \is_array($fields) || $fields === [null]) {
                continue;
            }
            $line = $file->key() + 1;
            if (\count($fields) !== \count(self::HEADER)) {
                $this->logger->warning('Reklamacje: zła liczba pól, pomijam wiersz.', ['line' => $line]);
                yield null;

                continue;
            }
            [$ticketId, $nip, $createdAt, $category, $status, $body] = array_map('strval', $fields);
            $ticketId = trim($ticketId);
            $nip = trim($nip);
            try {
                $created = (new \DateTimeImmutable(trim($createdAt)))->setTimezone(new \DateTimeZone('UTC'));
            } catch (\Throwable) {
                $created = null;
            }
            if ($ticketId === '' || $nip === '' || $created === null) {
                $this->logger->warning('Reklamacje: pusty ticket_id/nip albo zła data, pomijam wiersz.', ['line' => $line]);
                yield null;

                continue;
            }

            yield [
                'id' => $ticketId,
                'doc' => [
                    'ticket_id' => $ticketId,
                    'nip' => $nip,
                    'created_at' => $created->format('Y-m-d\TH:i:s\Z'),
                    'category' => trim($category),
                    'status' => strtolower(trim($status)),
                    'body' => $body,
                ],
            ];
        }
    }

    /**
     * @param  list<array{id: string, doc: array<string, string>}>  $batch
     * @param  array<int, true>  $sellerIds
     */
    private function flush(array $batch, int &$indexed, int &$unmatched, int &$failed, array &$sellerIds): void
    {
        if ($batch === []) {
            return;
        }
        $body = [];
        foreach ($batch as $row) {
            $body[] = ['index' => ['_index' => $this->index, '_id' => $row['id']]];
            $body[] = $row['doc'];
        }
        $response = $this->client->bulk(['body' => $body])->asArray();

        $matched = [];
        foreach ($response['items'] as $item) {
            $op = $item['index'] ?? [];
            if (isset($op['error'])) {
                $failed++;
                $this->logger->warning('Reklamacje: ES odrzucił wiersz.', ['id' => $op['_id'] ?? null, 'error' => $op['error']['reason'] ?? null]);
            } elseif (($op['_index'] ?? '') === $this->unmatchedIndex) {
                $unmatched++;
            } else {
                $indexed++;
                $matched[] = (string) $op['_id'];
            }
        }
        if ($matched === []) {
            return;
        }

        $this->client->indices()->refresh(['index' => $this->index]);
        foreach ($this->resolved($matched) as $sellerId) {
            $sellerIds[$sellerId] = true;
        }
        // Ten sam ticket mógł wcześniej czekać w kolejce bez dopasowania.
        $this->deleteFromUnmatched($matched);
    }

    /** @return array<string, int> ticket_id → seller_id dla wierszy obecnych w `complaints` */
    private function resolved(array $ids): array
    {
        $hits = $this->client->search([
            'index' => $this->index,
            'body' => ['size' => \count($ids), '_source' => ['seller_id'], 'query' => ['ids' => ['values' => array_values($ids)]]],
        ])->asArray()['hits']['hits'];

        $resolved = [];
        foreach ($hits as $hit) {
            if (isset($hit['_source']['seller_id'])) {
                $resolved[(string) $hit['_id']] = (int) $hit['_source']['seller_id'];
            }
        }

        return $resolved;
    }

    /** @param  list<string>  $ids */
    private function deleteFromUnmatched(array $ids): void
    {
        $this->client->deleteByQuery([
            'index' => $this->unmatchedIndex,
            'conflicts' => 'proceed',
            'refresh' => true,
            'body' => ['query' => ['ids' => ['values' => $ids]]],
        ]);
    }

    /** @return list<string> */
    private function unmatchedIds(): array
    {
        $ids = [];
        $response = $this->client->search([
            'index' => $this->unmatchedIndex,
            'scroll' => '1m',
            'body' => ['size' => self::BATCH, '_source' => false, 'query' => ['match_all' => new \stdClass]],
        ])->asArray();
        while (($hits = $response['hits']['hits']) !== []) {
            foreach ($hits as $hit) {
                $ids[] = (string) $hit['_id'];
            }
            $response = $this->client->scroll(['scroll' => '1m', 'body' => ['scroll_id' => $response['_scroll_id']]])->asArray();
        }
        $this->client->clearScroll(['body' => ['scroll_id' => $response['_scroll_id']]]);

        return $ids;
    }

    private function executePolicy(): void
    {
        $this->client->enrich()->executePolicy(['name' => self::POLICY, 'wait_for_completion' => true]);
    }
}
