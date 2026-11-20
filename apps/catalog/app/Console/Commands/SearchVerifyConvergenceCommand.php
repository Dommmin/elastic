<?php

namespace App\Console\Commands;

use App\Services\Search\ProductProjectionBuilder;
use App\Services\Search\TouchedProductIds;
use Carbon\Carbon;
use Elastic\Elasticsearch\Client;
use Illuminate\Console\Command;

/**
 * `php artisan search:verify-convergence --since=<ISO 8601>` — dowód zbieżności
 * po migracji pod ruchem (ETAP 10): każdy produkt dotknięty od `--since`
 * (zdarzenia w `outbox`) musi wyglądać w aliasie odczytu tak samo jak świeża
 * projekcja z Postgresa. Uruchamiać po opróżnieniu kolejki, inaczej dokumenty
 * "w locie" dadzą fałszywe rozjazdy.
 *
 * Id produktów wyznacza `TouchedProductIds` — ta sama reguła co endpoint
 * `/api/internal/products/changed` (nadrabianie po reindex-abort).
 */
class SearchVerifyConvergenceCommand extends Command
{
    private const ALIAS = 'products-search';

    /** Ceny to scaled_float(100): ES trzyma wartości z dokładnością do 0,01. */
    private const PRICE_TOLERANCE = 0.005;

    private const MAX_LISTED = 20;

    protected $signature = 'search:verify-convergence {--since= : Początek okna, ISO 8601} {--batch=500 : Rozmiar partii _mget}';

    protected $description = 'Porównuje produkty dotknięte od --since (outbox) między świeżą projekcją z bazy a aliasem products-search';

    public function __construct(
        private readonly Client $client,
        private readonly ProductProjectionBuilder $projections,
        private readonly TouchedProductIds $touched,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $since = $this->option('since');
        if (! is_string($since) || $since === '') {
            $this->error('Podaj --since w formacie ISO 8601, np. 2026-11-19T10:00:00+00:00.');

            return self::INVALID;
        }

        try {
            $sinceAt = Carbon::parse($since);
        } catch (\Throwable) {
            $this->error("Nieprawidłowa data --since: {$since}");

            return self::INVALID;
        }

        $ids = $this->touched->since($sinceAt);
        $mismatches = [];

        foreach (array_chunk($ids, max(1, (int) $this->option('batch'))) as $chunk) {
            $docs = $this->fetchDocuments($chunk);

            foreach ($chunk as $id) {
                $problem = $this->compare($id, $docs[$id] ?? null);
                if ($problem !== null) {
                    $mismatches[$id] = $problem;
                }
            }
        }

        $this->line(sprintf('checked=%d mismatches=%d', count($ids), count($mismatches)));

        foreach (array_slice($mismatches, 0, self::MAX_LISTED, true) as $id => $problem) {
            $this->line("  {$id}: {$problem}");
        }
        if (count($mismatches) > self::MAX_LISTED) {
            $this->line(sprintf('  ... i %d więcej', count($mismatches) - self::MAX_LISTED));
        }

        return $mismatches === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>> id => _source dla znalezionych dokumentów
     */
    private function fetchDocuments(array $ids): array
    {
        $response = $this->client->mget([
            'index' => self::ALIAS,
            'body' => ['ids' => array_map('strval', $ids)],
            '_source' => ['price_min', 'price_max', 'in_stock', 'offers.offer_id'],
        ]);

        $found = [];
        foreach ($response['docs'] ?? [] as $doc) {
            if (($doc['found'] ?? false) === true) {
                $found[(int) $doc['_id']] = $doc['_source'] ?? [];
            }
        }

        return $found;
    }

    /** @param  array<string, mixed>|null  $doc */
    private function compare(int $id, ?array $doc): ?string
    {
        $expected = $this->projections->build($id, 0);

        if ($expected === null) {
            return $doc === null ? null : 'zombie (produkt usunięty, dokument nadal w ES)';
        }

        if ($doc === null) {
            return 'brak dokumentu w ES';
        }

        $diffs = [];
        foreach (['price_min', 'price_max'] as $field) {
            if (! $this->samePrice($expected[$field], $doc[$field] ?? null)) {
                $diffs[] = sprintf('%s db=%s es=%s', $field, json_encode($expected[$field]), json_encode($doc[$field] ?? null));
            }
        }

        if ((bool) $expected['in_stock'] !== (bool) ($doc['in_stock'] ?? false)) {
            $diffs[] = sprintf('in_stock db=%s es=%s', json_encode($expected['in_stock']), json_encode($doc['in_stock'] ?? null));
        }

        $dbOffers = $this->offerIds($expected['offers']);
        $esOffers = $this->offerIds($doc['offers'] ?? []);
        if ($dbOffers !== $esOffers) {
            $diffs[] = sprintf('offers.offer_id db=[%s] es=[%s]', implode(',', $dbOffers), implode(',', $esOffers));
        }

        return $diffs === [] ? null : implode('; ', $diffs);
    }

    private function samePrice(mixed $db, mixed $es): bool
    {
        if ($db === null || $es === null) {
            return $db === $es;
        }

        return abs((float) $db - (float) $es) <= self::PRICE_TOLERANCE;
    }

    /** @return list<string> */
    private function offerIds(iterable $offers): array
    {
        $ids = [];
        foreach ($offers as $offer) {
            $ids[] = (string) $offer['offer_id'];
        }
        sort($ids);

        return $ids;
    }
}
