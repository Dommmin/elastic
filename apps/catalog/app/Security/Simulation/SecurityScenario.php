<?php

namespace App\Security\Simulation;

use App\Security\ClientFingerprint;
use App\Seller360\Simulation\DeterministicUlid;
use App\Support\Outbox\EventEnvelope;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use Random\Randomizer;

/**
 * Scenariusz symulatora detekcji nadużyć (ETAP 15, `security:simulate`): koperty z datą wsteczną, reklamacje
 * i prawda podstawowa (przypadki `planted` i `near_miss`) dla pięciu reguł. Nie dotyka bazy ani brokera.
 *
 * Determinizm: jeden `Randomizer` (Mt19937) w stałej kolejności — tasowanie pul, reguły po kolei, tło.
 * To samo ziarno i te same wejścia (oferty, sprzedawcy, `now`) dają identyczne koperty, CSV i prawdę.
 *
 * Jednoznaczność (żeby wynik reguły zależał od reguły, a nie od przypadku w danych):
 *  - oferty scenariuszy dostają tylko swoje zmiany cen, tło losuje z pozostałych; zmiany tła mają |pct| ≤ 15,
 *    a kliknięć tła nie ma, więc tło nie spełni ani `price-hug`, ani sekwencji przynęty;
 *  - przynęta i pudło „27% na ofercie < p95” leżą na produktach z popularnością < p95/2, więc ich podwyżki
 *    ~28% nie odpalają `price-hug`; zasadzone `price-hug` są na produktach z popularnością ≥ 1,05 × p95;
 *  - każda recenzja tła i `fresh-account` ma własny adres IP i własnego użytkownika (żaden hasz nie zbierze
 *    5 użytkowników w godzinę), konto tła ma ≥ 48 h (żadna piątka tła nie jest „świeża”); bursty biorą adresy
 *    z 192.0.2.0/24, zarezerwowanej tylko dla nich;
 *  - szum reklamacji 0–3 dziennie nigdy nie osiąga minimum 5; skoki leżą ≥ 8 dni od początku szumu, więc
 *    detektor ma pełne 7 dni historii i pierwszą reklamację ≥ 7 dni wcześniej.
 *
 * Surowe IP żyje tylko w pamięci: do kopert trafia hasz z tego samego `ClientFingerprint` co w sklepie
 * i prefiks sieci.
 */
final class SecurityScenario
{
    /** Znacznik w `data.simulation`: ProductSyncHandler i Seller360EventHandler (search) pomijają takie zdarzenia. */
    public const SIMULATION = 'security';

    /** Fałszywi użytkownicy (R1: bez wierszy w bazie) — poza pulami `analytics:simulate` (900M+) i seller-360 (910M+). */
    public const USER_ID_BASE = 920_000_000;

    public const REVIEW_ID_BASE = 920_000_000;

    public const SESSION_PREFIX = 'secsim-';

    public const TICKET_PREFIX = 'SEC';

    public const MIN_DAYS = 10;

    public const RULES = ['price-hug-threshold', 'fresh-account-5star', 'review-ip-burst', 'price-bait-and-switch', 'complaints-spike'];

    /** Zasadzony `price-hug` tylko na produktach z popularnością ≥ p95 × HIGH_MARGIN (enrich może być chwilę starszy). */
    public const HIGH_MARGIN = 1.05;

    /** Przynęta i pudło „< p95” tylko na produktach z popularnością < p95 × LOW_MARGIN. */
    public const LOW_MARGIN = 0.5;

    public const NOISE_SELLERS = 20;

    private const REVIEWS_PER_DAY = 40;

    private const PRICE_CHANGES_PER_DAY = 30;

    private const HOUR = 3_600;

    private const DAY = 86_400;

    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_6) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148',
        'Mozilla/5.0 (X11; Linux x86_64; rv:133.0) Gecko/20100101 Firefox/133.0',
    ];

    private const COMPLAINT_STATUSES = ['open', 'in_progress', 'closed'];

    private readonly DateTimeImmutable $now;

    private readonly int $start;

    private readonly int $end;

    /** @var list<array<string, mixed>> */
    private array $envelopes = [];

    /** @var list<array{rule: string, type: string, entity_type: string, entity_id: string, window_start: string, window_end: string}> */
    private array $cases = [];

    /** @var list<string> surowe adresy (tylko do testu, że nie wyciekły) */
    private array $ips = [];

    /** @var array<int, int> bieżąca cena oferty w symulacji */
    private array $prices = [];

    private int $nextUser = 0;

    private int $nextReview = 0;

    private int $nextUniqueIp = 0;

    private int $nextBurstIp = 10;

    /**
     * @param  list<array{category: string, body: string}>  $complaintTemplates  tylko szablony bez „uszkodz…” (seller-360 ich nie widzi)
     */
    public function __construct(
        private readonly Randomizer $random,
        private readonly ClientFingerprint $fingerprint,
        DateTimeImmutable $now,
        private readonly int $days,
        private readonly int $plant,
        private readonly array $complaintTemplates,
    ) {
        if ($days < self::MIN_DAYS || $plant < 1) {
            throw new InvalidArgumentException('--days musi być ≥ '.self::MIN_DAYS.', --plant ≥ 1.');
        }
        $this->now = $now->setTimezone(new DateTimeZone('UTC'));
        $this->end = $this->now->getTimestamp() - self::HOUR;
        $this->start = $this->now->getTimestamp() - $days * self::DAY + self::HOUR;
    }

    /** Ofert z produktów ≥ p95 potrzeba: zasadzone + pudła „24%”. */
    public static function highOffersNeeded(int $plant): int
    {
        return $plant + intdiv($plant + 1, 2);
    }

    /** Ofert z produktów < p95/2 potrzeba: pudła „27%” + przynęty (zasadzone i pudła). */
    public static function lowOffersNeeded(int $plant): int
    {
        return intdiv($plant, 2) + 2 * $plant;
    }

    public static function sellersNeeded(int $plant): int
    {
        return 2 * $plant;
    }

    /**
     * @param  list<array{id: int, product_id: int, seller_id: int, price_cents: int}>  $highOffers  oferty produktów ≥ p95 × HIGH_MARGIN
     * @param  list<array{id: int, product_id: int, seller_id: int, price_cents: int}>  $lowOffers  oferty produktów < p95 × LOW_MARGIN
     * @param  list<array{id: int, product_id: int, seller_id: int, price_cents: int}>  $backgroundOffers  pula tła (dowolne)
     * @param  list<array{id: int, nip: string}>  $sellers  syntetyczni sprzedawcy ETAPU 13 dopuszczeni do reklamacji
     * @return array{envelopes: list<array<string, mixed>>, cases: list<array<string, string>>, complaints: list<array{nip: string, complaints: list<array<string, mixed>>}>, ips: list<string>}
     */
    public function generate(array $highOffers, array $lowOffers, array $backgroundOffers, array $sellers): array
    {
        if (count($highOffers) < self::highOffersNeeded($this->plant) || count($lowOffers) < self::lowOffersNeeded($this->plant)) {
            throw new InvalidArgumentException('Za mało ofert po obu stronach progu p95 dla --plant='.$this->plant.'.');
        }
        if (count($sellers) < self::sellersNeeded($this->plant)) {
            throw new InvalidArgumentException('Za mało syntetycznych sprzedawców ETAPU 13 dla --plant='.$this->plant.'.');
        }

        $high = $this->random->shuffleArray($highOffers);
        $low = $this->random->shuffleArray($lowOffers);
        $sellers = $this->random->shuffleArray($sellers);
        $productIds = array_values(array_unique(array_column($backgroundOffers, 'product_id')));
        sort($productIds);
        if ($productIds === []) {
            throw new InvalidArgumentException('Pula ofert tła jest pusta.');
        }

        $used = [];
        $this->priceHug($high, $low, $used);
        $this->freshAccount($productIds);
        $this->reviewIpBurst($productIds);
        $this->baitAndSwitch($low, $used);
        $complaints = $this->complaints($sellers);
        $this->backgroundReviews($productIds);
        $this->backgroundPrices(array_values(array_filter($backgroundOffers, fn (array $o): bool => ! isset($used[$o['id']]))));

        $order = array_keys($this->envelopes);
        usort($order, fn (int $a, int $b): int => [$this->envelopes[$a]['occurred_at'], $a] <=> [$this->envelopes[$b]['occurred_at'], $b]);

        return [
            'envelopes' => array_map(fn (int $i): array => $this->envelopes[$i], $order),
            'cases' => $this->cases,
            'complaints' => $complaints,
            'ips' => $this->ips,
        ];
    }

    /**
     * Zasadzone: podwyżka 25,5–29,5% na ofercie produktu ≥ p95. Pudła na przemian: 24% na ofercie ≥ p95
     * i 26–28% na ofercie < p95/2.
     *
     * @param  list<array{id: int, product_id: int, seller_id: int, price_cents: int}>  $high
     * @param  list<array{id: int, product_id: int, seller_id: int, price_cents: int}>  $low
     * @param  array<int, true>  $used
     */
    private function priceHug(array &$high, array &$low, array &$used): void
    {
        $rule = 'price-hug-threshold';
        for ($i = 0; $i < $this->plant; $i++) {
            $offer = $this->take($high, $used);
            $at = $this->at($this->randomTime($this->start, $this->end));
            $this->priceChange($offer, $this->raise($offer, $this->random->getFloat(25.5, 29.5), 25, 30), $at);
            $this->addCase($rule, 'planted', 'offer', (string) $offer['id'], $at, $at);
        }
        for ($i = 0; $i < $this->plant; $i++) {
            [$offer, $pct, $min, $max] = $i % 2 === 0
                ? [$this->take($high, $used), 24.0, 23.5, 24.5]
                : [$this->take($low, $used), $this->random->getFloat(26.0, 28.0), 25.5, 28.5];
            $at = $this->at($this->randomTime($this->start, $this->end));
            $this->priceChange($offer, $this->raise($offer, $pct, $min, $max), $at);
            $this->addCase($rule, 'near_miss', 'offer', (string) $offer['id'], $at, $at);
        }
    }

    /**
     * Zasadzone: piątka od konta 2–20 h. Pudła: piątka od konta 30 h.
     *
     * @param  list<int>  $productIds
     */
    private function freshAccount(array $productIds): void
    {
        foreach (['planted', 'near_miss'] as $type) {
            for ($i = 0; $i < $this->plant; $i++) {
                $user = $this->user();
                $at = $this->at($this->randomTime($this->start, $this->end));
                $age = $type === 'planted' ? $this->random->getInt(2, 20) : 30;
                $this->review($user, $this->pick($productIds), 5, $age, $this->uniqueIp(), $at);
                $this->addCase('fresh-account-5star', $type, 'user', (string) $user, $at, $at);
            }
        }
    }

    /**
     * Zasadzone: 5–8 kont z jednego IP w jednej godzinie UTC. Pudła: 4 konta.
     *
     * @param  list<int>  $productIds
     */
    private function reviewIpBurst(array $productIds): void
    {
        foreach (['planted', 'near_miss'] as $type) {
            for ($i = 0; $i < $this->plant; $i++) {
                $ip = '192.0.2.'.$this->nextBurstIp++;
                $this->ips[] = $ip;
                $hourStart = intdiv($this->randomTime($this->start + self::HOUR, $this->end - self::HOUR), self::HOUR) * self::HOUR;
                $users = $type === 'planted' ? $this->random->getInt(5, 8) : 4;
                for ($u = 0; $u < $users; $u++) {
                    $at = $this->at($hourStart + $this->random->getInt(60, self::HOUR - 60));
                    $this->review($this->user(), $this->pick($productIds), $this->random->getInt(1, 5), $this->random->getInt(48, 5_000), $ip, $at);
                }
                $hash = (string) $this->fingerprint->hash($ip);
                $this->addCase('review-ip-burst', $type, 'ip_hash', $hash, $this->at($hourStart), $this->at($hourStart + self::HOUR));
            }
        }
    }

    /**
     * Zasadzone: obniżka 15,5–17%, 25–32 kliknięcia w 20 h, dwie podwyżki do 1,31–1,38 × ceny sprzed obniżki,
     * ostatnia ≤ 40 h od obniżki. Pudła na przemian: cena końcowa 1,28× (post-filtr) albo podwyżki po 52 i 60 h
     * (maxspan 48 h).
     *
     * @param  list<array{id: int, product_id: int, seller_id: int, price_cents: int}>  $low
     * @param  array<int, true>  $used
     */
    private function baitAndSwitch(array &$low, array &$used): void
    {
        foreach (['planted', 'near_miss'] as $type) {
            for ($i = 0; $i < $this->plant; $i++) {
                $offer = $this->take($low, $used);
                $late = $type === 'near_miss' && $i % 2 === 1;
                $ratio = $type === 'near_miss' && ! $late ? 1.28 : $this->random->getFloat(1.31, 1.38);
                $t0 = $this->randomTime($this->start, $this->end - 61 * self::HOUR);

                $before = $this->prices[$offer['id']];
                $dropped = (int) round($before * (1 - $this->random->getFloat(0.155, 0.17)));
                $final = $ratio === 1.28 ? (int) floor($before * $ratio) : (int) ceil($before * $ratio);
                $middle = (int) round(sqrt($dropped * $final));
                $this->assertBait($before, $dropped, $middle, $final, $ratio >= 1.3);

                $this->priceChange($offer, $dropped, $this->at($t0));
                $clicks = $this->random->getInt(25, 32);
                $offsets = [];
                for ($c = 0; $c < $clicks; $c++) {
                    $offsets[] = $this->random->getInt(600, 20 * self::HOUR);
                }
                sort($offsets);
                foreach ($offsets as $c => $offset) {
                    $this->click($offer, $c, $this->at($t0 + $offset));
                }
                [$rise1, $rise2] = $late
                    ? [$t0 + 52 * self::HOUR + $this->random->getInt(0, 1_800), $t0 + 60 * self::HOUR + $this->random->getInt(0, 1_800)]
                    : [$t0 + $this->random->getInt(24 * self::HOUR, 30 * self::HOUR), $t0 + $this->random->getInt(32 * self::HOUR, 40 * self::HOUR)];
                $this->priceChange($offer, $middle, $this->at($rise1));
                $this->priceChange($offer, $final, $this->at($rise2));

                $this->addCase('price-bait-and-switch', $type, 'offer', (string) $offer['id'], $this->at($t0), $this->at($rise2));
            }
        }
    }

    /**
     * Reklamacje: dzienny szum 0–3 dla sprzedawców przypadków i NOISE_SELLERS innych. Zasadzony skok ≥ średnia
     * + 4σ (i ≥ 8) z 7 poprzednich dni; pudło ≥ 5 i ~2σ, z zapasem ≥ 1 reklamacji pod progiem 3σ.
     *
     * @param  list<array{id: int, nip: string}>  $sellers
     * @return list<array{nip: string, complaints: list<array<string, mixed>>}>
     */
    private function complaints(array $sellers): array
    {
        $caseSellers = 2 * $this->plant;
        $chosen = array_slice($sellers, 0, $caseSellers + self::NOISE_SELLERS);
        $today = $this->now->setTime(0, 0);

        $rows = [];
        foreach ($chosen as $n => $seller) {
            // Indeks = liczba dni wstecz (1 = wczoraj); dziś pomijamy, żeby żadna reklamacja nie była z przyszłości.
            $daily = [];
            for ($ago = 1; $ago <= $this->days; $ago++) {
                $daily[$ago] = $this->random->getInt(0, 3);
            }

            if ($n < $caseSellers) {
                $type = $n < $this->plant ? 'planted' : 'near_miss';
                $spikeAgo = $this->random->getInt(1, $this->days - 8);
                [$history, $count] = $type === 'planted' ? $this->plantedSpike() : $this->nearMissSpike();
                foreach ($history as $k => $value) {
                    $daily[$spikeAgo + 1 + $k] = $value;
                }
                $daily[$spikeAgo] = $count;
                $day = $today->modify("-{$spikeAgo} days");
                $this->addCase('complaints-spike', $type, 'seller', (string) $seller['id'], $day, $day->modify('+1 day'));
            }

            $complaints = [];
            foreach ($daily as $ago => $count) {
                for ($c = 0; $c < $count; $c++) {
                    $template = $this->complaintTemplates[$this->random->getInt(0, count($this->complaintTemplates) - 1)];
                    $complaints[] = [
                        'days_ago' => $ago,
                        'seconds' => $this->random->getInt(0, self::DAY - 1),
                        'category' => $template['category'],
                        'status' => self::COMPLAINT_STATUSES[$this->random->getInt(0, count(self::COMPLAINT_STATUSES) - 1)],
                        'body' => $template['body'],
                    ];
                }
            }
            $rows[] = ['nip' => $seller['nip'], 'complaints' => $complaints];
        }

        return $rows;
    }

    /**
     * @return array{list<int>, int} historia 7 dni (od najbliższego dnia wstecz) i liczba w dobie skoku
     */
    private function plantedSpike(): array
    {
        $history = $this->history(3);
        [$mean, $std] = self::meanStd($history);

        return [$history, max(8, (int) ceil($mean + 4 * $std))];
    }

    /**
     * @return array{list<int>, int}
     */
    private function nearMissSpike(): array
    {
        for ($attempt = 0; $attempt < 500; $attempt++) {
            $history = $this->history(4);
            [$mean, $std] = self::meanStd($history);
            $count = max(5, (int) round($mean + 2 * $std));
            if ($count + 1 <= $mean + 3 * $std) {
                return [$history, $count];
            }
        }

        throw new LogicException('Nie udało się dobrać bliskiego pudła complaints-spike (~2σ, ≥ 5).');
    }

    /**
     * Historia 7 dni z wartościami 0..max (< 5, więc sama nie alarmuje); najstarszy dzień ≥ 1 — pierwsza
     * reklamacja sprzedawcy leży wtedy co najmniej 7 dni przed skokiem.
     *
     * @return list<int>
     */
    private function history(int $max): array
    {
        $history = [];
        for ($k = 0; $k < 7; $k++) {
            $history[] = $this->random->getInt($k === 6 ? 1 : 0, $max);
        }

        return $history;
    }

    /**
     * Średnia i σ populacyjne — jak `MovingFunctions.stdDev` w detektorze.
     *
     * @param  list<int>  $values
     * @return array{float, float}
     */
    public static function meanStd(array $values): array
    {
        $mean = array_sum($values) / count($values);
        $variance = array_sum(array_map(fn (int $v): float => ($v - $mean) ** 2, $values)) / count($values);

        return [$mean, sqrt($variance)];
    }

    /**
     * @param  list<int>  $productIds
     */
    private function backgroundReviews(array $productIds): void
    {
        for ($i = 0; $i < self::REVIEWS_PER_DAY * $this->days; $i++) {
            $at = $this->at($this->randomTime($this->start, $this->end));
            $this->review($this->user(), $this->pick($productIds), $this->random->getInt(1, 5), $this->random->getInt(48, 20_000), $this->uniqueIp(), $at);
        }
    }

    /**
     * Zwykłe zmiany cen ±1–15% (także legalne podwyżki), w kolejności czasu, żeby łańcuch cen się zgadzał.
     *
     * @param  list<array{id: int, product_id: int, seller_id: int, price_cents: int}>  $offers
     */
    private function backgroundPrices(array $offers): void
    {
        if ($offers === []) {
            return;
        }
        $planned = [];
        for ($i = 0; $i < self::PRICE_CHANGES_PER_DAY * $this->days; $i++) {
            $planned[] = [
                $this->randomTime($this->start, $this->end),
                $offers[$this->random->getInt(0, count($offers) - 1)],
                $this->random->getFloat(1.0, 15.0) * ($this->random->getInt(0, 1) === 1 ? 1 : -1),
            ];
        }
        usort($planned, fn (array $a, array $b): int => [$a[0], $a[1]['id']] <=> [$b[0], $b[1]['id']]);

        foreach ($planned as [$time, $offer, $pct]) {
            $this->prices[$offer['id']] ??= $offer['price_cents'];
            $old = $this->prices[$offer['id']];
            $new = max(100, (int) round($old * (1 + $pct / 100)));
            if ($new === $old || abs(self::pct($old, $new)) > 15.5) {
                continue;
            }
            $this->priceChange($offer, $new, $this->at($time));
        }
    }

    /**
     * Nowa cena po podwyżce o `$pct`, sprawdzona po zaokrągleniu do groszy (reguły widzą `change_pct` z groszy).
     *
     * @param  array{id: int, price_cents: int}  $offer
     */
    private function raise(array $offer, float $pct, float $min, float $max): int
    {
        $old = $this->prices[$offer['id']];
        $new = (int) round($old * (1 + $pct / 100));
        $actual = self::pct($old, $new);
        if ($actual < $min || $actual > $max) {
            throw new LogicException("Zmiana ceny {$actual}% poza zakresem [{$min}, {$max}] (cena {$old}).");
        }

        return $new;
    }

    private function assertBait(int $before, int $dropped, int $middle, int $final, bool $passes): void
    {
        $steps = [self::pct($before, $dropped), self::pct($dropped, $middle), self::pct($middle, $final)];
        $valid = $steps[0] <= -15 && $steps[0] >= -30 && $steps[1] > 0 && $steps[1] <= 30 && $steps[2] > 0 && $steps[2] <= 30;
        if (! $valid || ($final * 10 >= $before * 13) !== $passes) {
            throw new LogicException(sprintf('Przynęta %d → %d → %d → %d nie spełnia założeń scenariusza.', $before, $dropped, $middle, $final));
        }
    }

    public static function pct(int $old, int $new): float
    {
        return round(($new - $old) / $old * 100, 2);
    }

    /**
     * @param  array{id: int, product_id: int, seller_id: int, price_cents: int}  $offer
     */
    private function priceChange(array $offer, int $new, DateTimeImmutable $at): void
    {
        $old = $this->prices[$offer['id']] ?? $offer['price_cents'];
        $this->prices[$offer['id']] = $new;

        $this->envelopes[] = EventEnvelope::make(
            DeterministicUlid::make($at, $this->random), 'offer.price_changed', $at, 'offer', (string) $offer['id'], 0,
            $this->marked([
                'id' => $offer['id'],
                'product_id' => $offer['product_id'],
                'seller_id' => $offer['seller_id'],
                'price_cents' => $new,
                'currency' => 'PLN',
                'old_price_cents' => $old,
                'new_price_cents' => $new,
            ]),
        );
    }

    private function review(int $user, int $productId, int $rating, int $ageHours, string $ip, DateTimeImmutable $at): void
    {
        $id = self::REVIEW_ID_BASE + ++$this->nextReview;
        $this->envelopes[] = EventEnvelope::make(
            DeterministicUlid::make($at, $this->random), 'review.created', $at, 'review', (string) $id, 1,
            $this->marked([
                'id' => $id,
                'product_id' => $productId,
                'user_id' => $user,
                'rating' => $rating,
                'body' => null,
                'created_at' => $at->format(DateTimeInterface::ATOM),
                'client_ip_hash' => $this->fingerprint->hash($ip),
                'client_ip_prefix' => $this->fingerprint->prefix($ip),
                'client_user_agent' => self::USER_AGENTS[$this->random->getInt(0, count(self::USER_AGENTS) - 1)],
                'user_account_age_hours' => $ageHours,
            ]),
        );
    }

    /**
     * @param  array{id: int, product_id: int}  $offer
     */
    private function click(array $offer, int $n, DateTimeImmutable $at): void
    {
        $this->envelopes[] = EventEnvelope::make(
            DeterministicUlid::make($at, $this->random), 'user.clicked_result', $at, 'session', self::SESSION_PREFIX."{$offer['id']}-{$n}", 0,
            $this->marked([
                'product_id' => $offer['product_id'],
                'offer_id' => $offer['id'],
                'position' => $this->random->getInt(1, 10),
                'user_id' => $this->user(),
            ]),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function marked(array $data): array
    {
        return $data + ['synthetic' => true, 'simulation' => self::SIMULATION];
    }

    private function addCase(string $rule, string $type, string $entityType, string $entityId, DateTimeImmutable $start, DateTimeImmutable $end): void
    {
        $this->cases[] = [
            'rule' => $rule,
            'type' => $type,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'window_start' => $start->format(DateTimeInterface::ATOM),
            'window_end' => $end->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param  list<array{id: int, product_id: int, seller_id: int, price_cents: int}>  $pool
     * @param  array<int, true>  $used
     * @return array{id: int, product_id: int, seller_id: int, price_cents: int}
     */
    private function take(array &$pool, array &$used): array
    {
        while ($pool !== []) {
            $offer = array_shift($pool);
            if (! isset($used[$offer['id']])) {
                $used[$offer['id']] = true;
                $this->prices[$offer['id']] = $offer['price_cents'];

                return $offer;
            }
        }

        throw new InvalidArgumentException('Pula ofert wyczerpana.');
    }

    /**
     * Unikalny adres z 198.51.100.0/24, 203.0.113.0/24, potem 2001:db8::/32 — każdy użyty jeden raz.
     */
    private function uniqueIp(): string
    {
        $i = $this->nextUniqueIp++;
        $ip = match (true) {
            $i < 254 => '198.51.100.'.($i + 1),
            $i < 508 => '203.0.113.'.($i - 253),
            default => sprintf('2001:db8:%x:%x::%x', 0x100 + intdiv($i, 0x10000), $i % 0x10000, $this->random->getInt(1, 0xFFF)),
        };
        $this->ips[] = $ip;

        return $ip;
    }

    private function user(): int
    {
        return self::USER_ID_BASE + ++$this->nextUser;
    }

    /**
     * @param  list<int>  $values
     */
    private function pick(array $values): int
    {
        return $values[$this->random->getInt(0, count($values) - 1)];
    }

    private function randomTime(int $from, int $to): int
    {
        return $this->random->getInt($from, $to);
    }

    private function at(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable('@'.$timestamp))->setTimezone(new DateTimeZone('UTC'));
    }
}
