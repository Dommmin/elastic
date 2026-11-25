<?php

namespace App\Security;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Psr\Log\LoggerInterface;

/**
 * Percolator zdarzeń bezpieczeństwa (ETAP 15): dla zapisanych dokumentów sprawdza reguły z `security-rules`
 * i zapisuje alerty. Dokumenty bierze z ES (są już po pipeline, więc mają `product.popularity`), a zapytanie
 * `percolate` kieruje na nie po referencji `index` + `id`.
 *
 * Ref z konsumenta wskazuje NAZWĘ data streamu, a percolate po referencji (jak każdy get) potrzebuje
 * konkretnego indeksu zapasowego: stream rozwiązujemy `_mget`-em po jego indeksach, od najnowszego.
 * Idempotencja: id alertu jest deterministyczne ({@see Alert::id()}), więc ponowna percolacja
 * (duplikat 409, powtórka po błędzie) nadpisuje te same alerty.
 */
class SecurityPercolator implements EventPercolator
{
    private const CHUNK = 100;

    public function __construct(
        private readonly Client $client,
        private readonly AlertWriter $alerts,
        private readonly LoggerInterface $logger,
        private readonly string $rulesIndex = 'security-rules',
    ) {
    }

    public function percolate(array $refs): int
    {
        $total = 0;
        foreach (array_chunk(array_values(array_unique($refs)), self::CHUNK) as $chunk) {
            $total += $this->percolateChunk($chunk);
        }

        return $total;
    }

    /** @param list<string> $refs */
    private function percolateChunk(array $refs): int
    {
        $docs = $this->resolve($refs);
        if ($docs === []) {
            return 0;
        }

        $body = [];
        foreach ($docs as $doc) {
            $body[] = ['index' => $this->rulesIndex];
            $body[] = [
                'size' => 50,
                '_source' => ['rule', 'params'],
                'query' => ['percolate' => ['field' => 'query', 'index' => $doc['index'], 'id' => $doc['id']]],
            ];
        }
        $responses = $this->client->msearch(['body' => $body])->asArray()['responses'] ?? [];

        $alerts = [];
        foreach (array_values($docs) as $i => $doc) {
            $response = $responses[$i] ?? [];
            if (isset($response['error'])) {
                throw new \RuntimeException(\sprintf('Percolacja %s/%s: %s', $doc['index'], $doc['id'], $response['error']['reason'] ?? $response['error']['type'] ?? 'błąd'));
            }
            foreach ($response['hits']['hits'] ?? [] as $hit) {
                $alert = $this->toAlert($hit['_source'] ?? [], $doc['source']);
                if ($alert !== null) {
                    $alerts[] = $alert;
                }
            }
        }

        return $this->alerts->write($alerts);
    }

    /**
     * @param  list<string>  $refs  `<indeks>/<_id>`
     * @return list<array{index: string, id: string, source: array<string, mixed>}> dokumenty, które istnieją
     */
    private function resolve(array $refs): array
    {
        $byIndex = [];
        foreach ($refs as $ref) {
            [$index, $id] = explode('/', $ref, 2) + [1 => ''];
            if ($index === '' || $id === '') {
                $this->logger->warning('Pomijam niepoprawną referencję dokumentu.', ['ref' => $ref]);

                continue;
            }
            $byIndex[$index][] = $id;
        }

        $found = [];
        foreach ($byIndex as $index => $ids) {
            $candidates = $this->backingIndices($index);
            $pending = $ids;
            // Nowy dokument leży w indeksie zapisu, więc zwykle pierwsza próba trafia w całość.
            foreach ($candidates as $candidate) {
                if ($pending === []) {
                    break;
                }
                $response = $this->client->mget(['index' => $candidate, 'body' => ['ids' => array_values($pending)]])->asArray();
                foreach ($response['docs'] ?? [] as $doc) {
                    if (($doc['found'] ?? false) === true) {
                        $found[] = ['index' => (string) $doc['_index'], 'id' => (string) $doc['_id'], 'source' => $doc['_source'] ?? []];
                        $pending = array_values(array_diff($pending, [(string) $doc['_id']]));
                    }
                }
            }
            if ($pending !== []) {
                $this->logger->warning('Dokumentów do percolacji nie ma w ES (usunięte albo jeszcze niewidoczne).', ['index' => $index, 'count' => \count($pending)]);
            }
        }

        return $found;
    }

    /** @return list<string> indeksy zapasowe streamu od najnowszego, a dla zwykłego indeksu on sam */
    private function backingIndices(string $index): array
    {
        try {
            $streams = $this->client->indices()->getDataStream(['name' => $index])->asArray()['data_streams'] ?? [];
        } catch (ClientResponseException $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                return [$index];
            }
            throw $e;
        }
        $names = array_map(static fn (array $i) => (string) $i['index_name'], $streams[0]['indices'] ?? []);

        return $names === [] ? [$index] : array_reverse($names);
    }

    /**
     * @param  array<string, mixed>  $rule    `_source` reguły (`rule`, `params`)
     * @param  array<string, mixed>  $source  `_source` zdarzenia
     */
    private function toAlert(array $rule, array $source): ?Alert
    {
        $params = $rule['params'] ?? [];
        $entityField = (string) ($params['entity_field'] ?? '');
        $entityId = self::path($source, $entityField);
        $at = self::path($source, '@timestamp');
        if ($entityField === '' || ! \is_scalar($entityId) || ! \is_string($at)) {
            $this->logger->warning('Reguła trafiła, ale zdarzenie nie ma encji albo czasu: alert pominięty.', ['rule' => $rule['rule']['id'] ?? '?']);

            return null;
        }

        $start = new \DateTimeImmutable($at);
        $evidence = [];
        foreach ((array) ($params['evidence_fields'] ?? []) as $field) {
            $value = self::path($source, (string) $field);
            if (\is_scalar($value)) {
                $evidence[str_replace('.', '_', (string) $field)] = $value;
            }
        }
        if (isset($params['p95'])) {
            $evidence['p95'] = (float) $params['p95'];
        }

        $entityType = (string) ($params['entity_type'] ?? 'unknown');

        return new Alert(
            ruleId: (string) $rule['rule']['id'],
            ruleKind: (string) $rule['rule']['kind'],
            severity: (string) $rule['rule']['severity'],
            entityType: $entityType,
            entityId: (string) $entityId,
            windowStart: $start,
            windowEnd: $start,
            message: \sprintf('%s (%s %s)', $rule['rule']['description'] ?? $rule['rule']['id'], $entityType, $entityId),
            evidence: $evidence,
            synthetic: self::path($source, 'labels.synthetic') === true,
        );
    }

    /** @param array<string, mixed> $source */
    private static function path(array $source, string $path): mixed
    {
        $value = $source;
        foreach (explode('.', $path) as $key) {
            if (! \is_array($value) || ! \array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }
}
