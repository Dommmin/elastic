<?php

namespace App\Security;

use Elastic\Elasticsearch\Client;

/**
 * Ładuje reguły percolatora z plików JSON do indeksu `security-rules` (ETAP 15).
 *
 * Plik: `{rule, params, query, alert}`. Gdy `params.p95_from` = `<indeks>.<pole>`, loader liczy
 * percentyl 95 tego pola, podstawia go za znacznik `"{{p95}}"` w zapytaniu i zapisuje w `params.p95`.
 * Dokument reguły ma `_id` = `rule.id`, więc ponowne ładowanie nadpisuje regułę (nowy próg).
 * Opis alertu (`alert`) nie ma własnych pól w ścisłym mapowaniu, więc ląduje w `params`
 * jako `entity_type`, `entity_field`, `window`, `evidence_fields` (flattened).
 */
class RuleLoader
{
    private const P95_MARKER = '"{{p95}}"';

    public function __construct(
        private readonly Client $client,
        private readonly string $rulesDir = '/infra/elasticsearch/detection/rules',
        private readonly string $rulesIndex = 'security-rules',
    ) {
    }

    /**
     * @param  ?float  $p95Override  wymusza próg zamiast liczyć go z danych (testy)
     * @return array<string, ?float> rule.id => użyty próg p95 (null: reguła bez progu)
     */
    public function load(bool $dryRun = false, ?float $p95Override = null): array
    {
        $files = glob(rtrim($this->rulesDir, '/').'/*.json') ?: [];
        if ($files === []) {
            throw new \RuntimeException("Brak plików reguł w {$this->rulesDir}.");
        }
        sort($files);

        $used = [];
        foreach ($files as $file) {
            [$id, $document, $p95] = $this->prepare($file, $p95Override);
            $used[$id] = $p95;
            if (! $dryRun) {
                $this->client->index(['index' => $this->rulesIndex, 'id' => $id, 'body' => $document, 'refresh' => 'true']);
            }
        }

        return $used;
    }

    /** @return array{0: string, 1: array<string, mixed>, 2: ?float} */
    private function prepare(string $file, ?float $p95Override): array
    {
        $rule = json_decode((string) file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR);
        $id = $rule['rule']['id'] ?? null;
        if (! \is_string($id) || $id === '' || ! isset($rule['query'])) {
            throw new \RuntimeException(basename($file).': brak rule.id albo query.');
        }

        $params = $rule['params'] ?? [];
        $p95 = null;
        $query = json_encode($rule['query'], \JSON_THROW_ON_ERROR);
        if (isset($params['p95_from'])) {
            $p95 = $p95Override ?? $this->percentile95((string) $params['p95_from']);
            // Znacznik jest napisem w JSON-ie; podmieniamy go razem z cudzysłowami na liczbę.
            $query = str_replace(self::P95_MARKER, json_encode($p95, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), $query, $count);
            if ($count === 0) {
                throw new \RuntimeException("{$id}: params.p95_from bez znacznika {{p95}} w zapytaniu.");
            }
            $params['p95'] = $p95;
        } elseif (str_contains($query, self::P95_MARKER)) {
            throw new \RuntimeException("{$id}: znacznik {{p95}} bez params.p95_from.");
        }

        foreach ($rule['alert'] ?? [] as $key => $value) {
            $params[$key] = $value;
        }

        return [$id, [
            'rule' => $rule['rule'],
            'params' => $params,
            'query' => json_decode($query, true, flags: \JSON_THROW_ON_ERROR),
        ], $p95];
    }

    private function percentile95(string $source): float
    {
        [$index, $field] = array_pad(explode('.', $source, 2), 2, '');
        if ($index === '' || $field === '') {
            throw new \RuntimeException("params.p95_from musi mieć postać <indeks>.<pole>, jest: {$source}.");
        }

        $response = $this->client->search([
            'index' => $index,
            'body' => ['size' => 0, 'aggs' => ['p' => ['percentiles' => ['field' => $field, 'percents' => [95]]]]],
        ])->asArray();
        $value = $response['aggregations']['p']['values']['95.0'] ?? null;
        if (! is_numeric($value)) {
            throw new \RuntimeException("Brak danych do percentyla 95 z {$source}: najpierw make popularity-refresh.");
        }

        return (float) $value;
    }
}
