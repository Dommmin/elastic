<?php

namespace App\Security\Detect;

use App\Security\Alert;
use App\Security\Detector;
use Elastic\Elasticsearch\Client;

/**
 * Reguła `complaints-spike`: liczba reklamacji sprzedawcy w dobie UTC ≥ średnia + 3σ z 7 poprzednich
 * dni i co najmniej 5 reklamacji.
 *
 * Średnią i σ liczy sam Elasticsearch: na każdego sprzedawcę dzienny `date_histogram` z agregacjami
 * `moving_fn` po `_count`. Z `shift: 0` okno 7 kubełków kończy się PRZED bieżącym, więc dzień nie liczy
 * sam siebie (inaczej skok podniósłby własny próg). Histogram celowo NIE ma `extended_bounds` ani
 * zer przed pierwszą reklamacją: sprzedawca bez historii ma puste okno, `moving_fn` zwraca null i
 * `isSpike` nie strzela (nowy sprzedawca z 6 reklamacjami jednego dnia to nie skok, tylko start).
 * Dni bez reklamacji w środku historii wypełnia `min_doc_count: 0` zerami, więc rzadkie reklamacje
 * obniżają średnią, jak należy.
 *
 * Zakres: oceniamy każdą dobę UTC dotykającą `[from, to)` (job co 5 min z oknem 2 h ocenia bieżącą
 * dobę „do teraz”; backfill `--since=15d` ocenia wszystkie 15 dób). Okno alertu to cała doba, więc
 * kolejne przebiegi tej samej doby nadpisują ten sam dokument. Dowody (`significant_terms`) liczymy
 * tylko dla sprzedawców, którzy faktycznie przekroczyli próg.
 */
final class ComplaintsSpikeDetector implements Detector
{
    public const RULE_ID = 'complaints-spike';

    private const MIN_COUNT = 5;
    private const SIGMAS = 3;
    private const HISTORY_DAYS = 7;
    private const BACKGROUND_DAYS = 30;
    /** Ile identyfikatorów sprzedawców wchodzi do jednego zapytania z histogramem. */
    private const SELLER_CHUNK = 500;
    private const MAX_SELLERS = 10000;
    /** Prefiks identyfikatora zgłoszenia z symulatora (Task 9). */
    private const SIMULATOR_PREFIX = 'SEC-';

    public function __construct(
        private readonly Client $client,
        private readonly string $index = 'complaints',
    ) {
    }

    public function id(): string
    {
        return self::RULE_ID;
    }

    public static function isSpike(int $count, ?float $mean, ?float $std): bool
    {
        if ($mean === null || $count < self::MIN_COUNT) {
            return false;
        }

        return $count >= $mean + self::SIGMAS * ($std ?? 0.0);
    }

    public function detect(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $utc = new \DateTimeZone('UTC');
        $firstDay = $from->setTimezone($utc)->setTime(0, 0);
        $lastDay = $to->setTimezone($utc)->modify('-1 second')->setTime(0, 0);
        if ($lastDay < $firstDay) {
            return [];
        }
        $rangeEnd = $lastDay->modify('+1 day');
        $lookbackStart = $firstDay->modify('-'.self::HISTORY_DAYS.' days');

        $alerts = [];
        foreach (array_chunk($this->sellersWithComplaints($firstDay, $rangeEnd), self::SELLER_CHUNK) as $sellers) {
            foreach ($this->histograms($sellers, $lookbackStart, $rangeEnd) as $sellerId => $buckets) {
                foreach ($buckets as $bucket) {
                    $day = (new \DateTimeImmutable('@'.intdiv((int) $bucket['key'], 1000)))->setTimezone($utc);
                    if ($day < $firstDay || $day > $lastDay) {
                        continue;
                    }
                    $count = (int) $bucket['doc_count'];
                    $mean = $bucket['mean']['value'] ?? null;
                    $std = $bucket['std']['value'] ?? null;
                    $mean = \is_numeric($mean) ? (float) $mean : null;
                    $std = \is_numeric($std) ? (float) $std : null;
                    if (self::isSpike($count, $mean, $std)) {
                        $alerts[] = $this->alert($sellerId, $day, $count, (float) $mean, (float) $std, $bucket['history']);
                    }
                }
            }
        }

        return $alerts;
    }

    /** @return list<int> sprzedawcy z choć jedną reklamacją w `[from, to)` */
    private function sellersWithComplaints(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $response = $this->client->search(['index' => $this->index, 'body' => [
            'size' => 0,
            'query' => $this->dayRange($from, $to),
            'aggs' => ['sellers' => ['terms' => ['field' => 'seller_id', 'size' => self::MAX_SELLERS]]],
        ]])->asArray();

        $terms = $response['aggregations']['sellers'];
        if (($terms['sum_other_doc_count'] ?? 0) > 0) {
            throw new \RuntimeException(\sprintf('complaints-spike: więcej niż %d sprzedawców z reklamacjami w oknie; dziel zakres.', self::MAX_SELLERS));
        }

        return array_map(static fn (array $b): int => (int) $b['key'], $terms['buckets']);
    }

    /**
     * @param  list<int>  $sellers
     * @return array<int, list<array<string, mixed>>> kubełki dobowe na sprzedawcę; każdy z kluczem `history` (liczby 7 poprzednich kubełków)
     */
    private function histograms(array $sellers, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $response = $this->client->search(['index' => $this->index, 'body' => [
            'size' => 0,
            'query' => ['bool' => ['filter' => [
                $this->dayRange($from, $to),
                ['terms' => ['seller_id' => $sellers]],
            ]]],
            'aggs' => ['sellers' => [
                'terms' => ['field' => 'seller_id', 'size' => \count($sellers)],
                'aggs' => ['days' => [
                    'date_histogram' => ['field' => 'created_at', 'calendar_interval' => '1d', 'time_zone' => 'UTC', 'min_doc_count' => 0],
                    'aggs' => [
                        'mean' => ['moving_fn' => [
                            'buckets_path' => '_count', 'window' => self::HISTORY_DAYS, 'shift' => 0,
                            'script' => 'MovingFunctions.unweightedAvg(values)',
                        ]],
                        'std' => ['moving_fn' => [
                            'buckets_path' => '_count', 'window' => self::HISTORY_DAYS, 'shift' => 0,
                            'script' => 'MovingFunctions.stdDev(values, MovingFunctions.unweightedAvg(values))',
                        ]],
                    ],
                ]],
            ]],
        ]])->asArray();

        $result = [];
        foreach ($response['aggregations']['sellers']['buckets'] as $seller) {
            $buckets = $seller['days']['buckets'];
            foreach ($buckets as $i => $bucket) {
                $buckets[$i]['history'] = array_map(
                    static fn (array $b): int => (int) $b['doc_count'],
                    \array_slice($buckets, max(0, $i - self::HISTORY_DAYS), min($i, self::HISTORY_DAYS)),
                );
            }
            $result[(int) $seller['key']] = $buckets;
        }

        return $result;
    }

    /** @param list<int> $history */
    private function alert(int $sellerId, \DateTimeImmutable $day, int $count, float $mean, float $std, array $history): Alert
    {
        $end = $day->modify('+1 day');
        $categories = $this->topCategories($sellerId, $day, $end);

        return new Alert(
            ruleId: self::RULE_ID,
            ruleKind: 'aggregation',
            severity: 'medium',
            entityType: 'seller',
            entityId: (string) $sellerId,
            windowStart: $day,
            windowEnd: $end,
            message: \sprintf('Sprzedawca %d: %d reklamacji w dobie przy średniej %.1f (σ %.2f) z 7 poprzednich dni', $sellerId, $count, $mean, $std),
            evidence: [
                'count' => $count,
                'mean' => round($mean, 2),
                'std' => round($std, 2),
                'threshold' => round($mean + self::SIGMAS * $std, 2),
                'daily_counts' => implode(',', $history),
                'top_categories' => implode(', ', $categories),
                'seller_significant' => $this->isSignificantSeller($sellerId, $day, $end),
            ],
            synthetic: $this->isSynthetic($sellerId, $day, $end),
        );
    }

    /** @return list<string> do 3 kategorii „nietypowo częstych” u sprzedawcy względem całego indeksu (`kategoria (liczba)`) */
    private function topCategories(int $sellerId, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $response = $this->client->search(['index' => $this->index, 'body' => [
            'size' => 0,
            'query' => ['bool' => ['filter' => [$this->dayRange($start, $end), ['term' => ['seller_id' => $sellerId]]]]],
            'aggs' => ['categories' => ['significant_terms' => ['field' => 'category', 'size' => 3, 'min_doc_count' => 1]]],
        ]])->asArray();

        return array_map(
            static fn (array $b): string => \sprintf('%s (%d)', $b['key'], $b['doc_count']),
            $response['aggregations']['categories']['buckets'],
        );
    }

    /** Czy sprzedawca jest w `significant_terms` po `seller_id`: ostatnia doba kontra tło 30 dni. */
    private function isSignificantSeller(int $sellerId, \DateTimeImmutable $start, \DateTimeImmutable $end): bool
    {
        $response = $this->client->search(['index' => $this->index, 'body' => [
            'size' => 0,
            'query' => $this->dayRange($start, $end),
            'aggs' => ['sellers' => ['significant_terms' => [
                'field' => 'seller_id',
                'size' => 20,
                'min_doc_count' => 1,
                'background_filter' => $this->dayRange($end->modify('-'.self::BACKGROUND_DAYS.' days'), $end),
            ]]],
        ]])->asArray();

        foreach ($response['aggregations']['sellers']['buckets'] as $bucket) {
            if ((int) $bucket['key'] === $sellerId) {
                return true;
            }
        }

        return false;
    }

    /** Syntetyczny, gdy WSZYSTKIE reklamacje sprzedawcy z tej doby pochodzą z symulatora (`ticket_id` z prefiksem `SEC-`). */
    private function isSynthetic(int $sellerId, \DateTimeImmutable $start, \DateTimeImmutable $end): bool
    {
        $base = [$this->dayRange($start, $end), ['term' => ['seller_id' => $sellerId]]];
        $count = fn (array $filter): int => (int) $this->client->count([
            'index' => $this->index,
            'body' => ['query' => ['bool' => ['filter' => $filter]]],
        ])->asArray()['count'];

        $total = $count($base);

        return $total > 0 && $total === $count([...$base, ['prefix' => ['ticket_id' => self::SIMULATOR_PREFIX]]]);
    }

    /** @return array<string, mixed> */
    private function dayRange(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return ['range' => ['created_at' => [
            'gte' => $from->format(\DATE_ATOM),
            'lt' => $to->format(\DATE_ATOM),
        ]]];
    }
}
