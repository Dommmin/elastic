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
 * sam siebie (inaczej skok podniósłby własny próg). Histogram ma `extended_bounds` od 7 dni przed pierwszą
 * oceniana dobą i `min_doc_count: 0`, więc każda oceniana doba ma pełne, zerami uzupełnione okno 7 dni,
 * a werdykt nie zależy od długości zakresu (job 2 h i backfill 15 d dają to samo).
 * Brak historii wykrywamy osobno: dobę D oceniamy tylko, gdy PIERWSZA reklamacja sprzedawcy w ogóle
 * (min(created_at) bez filtra czasu) jest co najmniej 7 dni przed D. Nowy sprzedawca z 6 reklamacjami
 * to start, nie skok; `isSpike` nadal zwraca false dla `mean === null`.
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
    private const PAGE = 1000;
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
            $firstComplaints = $this->firstComplaints($sellers);
            foreach ($this->histograms($sellers, $lookbackStart, $lastDay, $rangeEnd) as $sellerId => $buckets) {
                foreach ($buckets as $bucket) {
                    $day = (new \DateTimeImmutable('@'.intdiv((int) $bucket['key'], 1000)))->setTimezone($utc);
                    if ($day < $firstDay || $day > $lastDay) {
                        continue;
                    }
                    $first = $firstComplaints[$sellerId] ?? null;
                    if ($first === null || $first > $day->modify('-'.self::HISTORY_DAYS.' days')) {
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

    /** @return list<int> sprzedawcy z choć jedną reklamacją w `[from, to)`; composite z `after_key`, bez sztywnego limitu */
    private function sellersWithComplaints(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $sellers = [];
        $after = null;
        do {
            $composite = ['size' => self::PAGE, 'sources' => [['seller' => ['terms' => ['field' => 'seller_id']]]]];
            if ($after !== null) {
                $composite['after'] = $after;
            }
            $response = $this->client->search(['index' => $this->index, 'body' => [
                'size' => 0,
                'query' => $this->dayRange($from, $to),
                'aggs' => ['sellers' => ['composite' => $composite]],
            ]])->asArray();

            $page = $response['aggregations']['sellers'];
            foreach ($page['buckets'] as $bucket) {
                $sellers[] = (int) $bucket['key']['seller'];
            }
            $after = $page['after_key'] ?? null;
        } while ($after !== null && $page['buckets'] !== []);

        return $sellers;
    }

    /**
     * Doba (UTC) pierwszej reklamacji sprzedawcy w całej historii (bez filtra czasu).
     *
     * @param  list<int>  $sellers
     * @return array<int, \DateTimeImmutable>
     */
    private function firstComplaints(array $sellers): array
    {
        $response = $this->client->search(['index' => $this->index, 'body' => [
            'size' => 0,
            'query' => ['terms' => ['seller_id' => $sellers]],
            'aggs' => ['sellers' => [
                'terms' => ['field' => 'seller_id', 'size' => \count($sellers)],
                'aggs' => ['first' => ['min' => ['field' => 'created_at']]],
            ]],
        ]])->asArray();

        $result = [];
        foreach ($response['aggregations']['sellers']['buckets'] as $bucket) {
            if (isset($bucket['first']['value'])) {
                $result[(int) $bucket['key']] = (new \DateTimeImmutable('@'.intdiv((int) $bucket['first']['value'], 1000)))->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);
            }
        }

        return $result;
    }

    /**
     * @param  list<int>  $sellers
     * @return array<int, list<array<string, mixed>>> kubełki dobowe na sprzedawcę; każdy z kluczem `history` (liczby 7 poprzednich kubełków)
     */
    private function histograms(array $sellers, \DateTimeImmutable $from, \DateTimeImmutable $lastDay, \DateTimeImmutable $to): array
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
                    'date_histogram' => ['field' => 'created_at', 'calendar_interval' => '1d', 'time_zone' => 'UTC', 'min_doc_count' => 0,
                        'extended_bounds' => ['min' => $from->format(\DATE_ATOM), 'max' => $lastDay->format(\DATE_ATOM)],
                    ],
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
                'size' => 1,
                'min_doc_count' => 1,
                'include' => [$sellerId],
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
