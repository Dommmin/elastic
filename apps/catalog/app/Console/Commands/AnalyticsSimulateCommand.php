<?php

namespace App\Console\Commands;

use App\Analytics\SearchedEventEmitter;
use App\Analytics\Simulation\Attractiveness;
use App\Analytics\Simulation\ClickModel;
use App\Analytics\Simulation\QueryPool;
use App\Analytics\Simulation\SimulatedEventPublisher;
use App\Models\Offer;
use App\Services\ProductSearchService;
use App\Services\Search\SearchCriteria;
use App\Support\Outbox\EventEnvelope;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Console\Command;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Symulator klientów dla analityki operacyjnej (ETAP 12, spec sekcja 8).
 *
 * Sesja (1–3 wyszukania) losuje frazy z `QueryPool`, dostaje PRAWDZIWY ranking
 * z `ProductSearchService`, klika wg `ClickModel` (bias pozycji × ukryta
 * atrakcyjność), część kliknięć kończy się koszykiem (P = 0.3·a), część
 * koszyków zakupem (P = 0.3). Zdarzenia mają kopertę i `data` identyczne
 * z UI (`SearchedEventEmitter`, `ProductPageController`, `CartController`)
 * i idą prosto do `marketplace.events` (`SimulatedEventPublisher`).
 *
 * Determinizm: każdy dzień ma własny generator z hasha (ziarno, data), więc
 * `--day=N` daje dokładnie dzień N pełnego przebiegu, a ponowny przebieg z tym
 * samym ziarnem daje te same sesje, czasy i ID wyszukań (ES odrzuci duplikaty
 * 409 — ale tylko w obrębie jednego indeksu zaplecza data streamu; ILM robi
 * rollover codziennie, więc powtórka po rolloverze zapisze duplikaty do nowego
 * indeksu). Kliknięcia zależą od rankingu: kolejność remisów w ES zmienia się między
 * wywołaniami, więc powtórka dnia może dopisać kliknięcia w inne produkty —
 * przed powtórką czyść data streamy albo zmień ziarno. Wyniki
 * wyszukiwań są cache'owane per fraza tylko w obrębie jednego wywołania —
 * pętla zamknięta (jeden dzień na wywołanie) widzi ranking po zmianie.
 */
class AnalyticsSimulateCommand extends Command
{
    protected $signature = 'analytics:simulate
        {--days=30 : Ile dni historii: [as-of − days, as-of)}
        {--sessions-per-day=300 : Sesji na dzień (z --live: sesji w paczce)}
        {--seed=1 : Ziarno: atrakcyjność, frazy, sesje}
        {--eta=1 : Wykładnik biasu pozycji p(k) = 1/k^eta}
        {--noise=0.05 : Udział sesji klikających losowe pozycje}
        {--flat-attractiveness : Kontrola: stała atrakcyjność 0.3 (kliknięcia zależą tylko od pozycji)}
        {--inject= : Anomalia: zero-results albo ctr-drop (ostatnia godzina ostatniego dnia, z --live cała paczka)}
        {--live : Paczka sesji w ostatnich 15 minutach zamiast historii}
        {--day= : Tylko dzień N (1 = najstarszy) — pętla zamknięta}
        {--as-of= : Koniec historii YYYY-MM-DD (domyślnie dziś 00:00 UTC)}
        {--force : Pozwól uruchomić na produkcji (sztuczne zdarzenia w prawdziwej analityce)}';

    protected $description = 'Symuluje ruch klientów (wyszukania, kliknięcia, koszyk, zakup) i publikuje zdarzenia user.* do RabbitMQ';

    private const INJECTIONS = ['zero-results', 'ctr-drop'];

    /** Udział wyszukań spoza katalogu w oknie anomalii zero-results. */
    private const ZERO_RESULTS_SHARE = 0.75;

    private const CTR_DROP_MULTIPLIER = 0.2;

    private const CART_FACTOR = 0.3;

    private const CHECKOUT_PROBABILITY = 0.3;

    /** Pula fałszywych użytkowników — poza zakresem prawdziwych ID. */
    private const USER_ID_BASE = 900_000_000;

    private const USER_POOL = 500;

    /**
     * Fałszywe ID zamówień: 900M + dzień od 2020-01-01 · 10M + numer sesji · 100 + k.
     * Rosną z czasem, nie zależą od innych sesji i nie zderzą się z prawdziwymi.
     */
    private const ORDER_ID_BASE = 900_000_000;

    private const ORDERS_PER_DAY = 10_000_000;

    private const ORDERS_PER_SESSION = 100;

    private const LIVE_MINUTES = 15;

    /**
     * Wagi godzin doby (UTC): więcej ruchu 10:00–22:00, mało w nocy.
     */
    private const HOUR_WEIGHTS = [
        0.5, 0.5, 0.5, 0.5, 0.5, 0.5, 0.5,
        1.5, 1.5, 1.5,
        3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3,
        1.5, 1.5,
    ];

    private QueryPool $pool;

    private ClickModel $clickModel;

    /** @var Closure(int): float */
    private Closure $attractiveness;

    /** @var array<int, float> */
    private array $attractivenessMemo = [];

    /** @var array<string, array{total: int, results: list<int>}> */
    private array $searchCache = [];

    /** @var array<int, array{id: int, seller_id: int, price_cents: int}|null> */
    private array $offerCache = [];

    /** @var array<string, int> */
    private array $stats = [];

    public function handle(ProductSearchService $search, SimulatedEventPublisher $publisher): int
    {
        $started = microtime(true);

        // Sztuczne sesje na produkcji zatrułyby popularność i alerty — tylko świadomie.
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Środowisko produkcyjne: symulator publikuje sztuczne zdarzenia do prawdziwej analityki. Uruchom z --force, jeśli na pewno tego chcesz.');

            return self::FAILURE;
        }

        $inject = $this->option('inject');
        $inject = $inject === null || $inject === '' ? null : (string) $inject;
        $days = (int) $this->option('days');
        $sessionsPerDay = (int) $this->option('sessions-per-day');
        $seed = (int) $this->option('seed');
        $eta = (float) $this->option('eta');
        $noise = (float) $this->option('noise');
        $day = $this->option('day');
        $day = $day === null || $day === '' ? null : (int) $day;

        $error = match (true) {
            $inject !== null && ! in_array($inject, self::INJECTIONS, true) => '--inject przyjmuje: '.implode(', ', self::INJECTIONS),
            $days < 1 => '--days musi być >= 1',
            $sessionsPerDay < 1 || $sessionsPerDay > 20_000 => '--sessions-per-day musi być w zakresie 1..20000',
            $day !== null && ($day < 1 || $day > $days) => "--day musi być w zakresie 1..{$days}",
            $eta <= 0 => '--eta musi być > 0',
            $noise < 0 || $noise > 1 => '--noise musi być w zakresie 0..1',
            default => null,
        };
        if ($error !== null) {
            $this->error($error);

            return self::FAILURE;
        }

        $windows = $this->windows($days, $day, $inject !== null);
        if ($windows === null) {
            $this->error('--as-of musi mieć format YYYY-MM-DD');

            return self::FAILURE;
        }

        // Instancja komendy bywa użyta ponownie w tym samym procesie (testy, Artisan::call):
        // cache rankingu żyje tylko jedno wywołanie, więc czyścimy stan na starcie.
        $this->searchCache = [];
        $this->offerCache = [];
        $this->attractivenessMemo = [];
        $this->pool = QueryPool::fromCatalog($seed);
        $this->attractiveness = $this->option('flat-attractiveness')
            ? static fn (int $productId): float => Attractiveness::FLAT
            : fn (int $productId): float => $this->attractivenessMemo[$productId] ??= Attractiveness::for($productId, $seed);
        $this->clickModel = new ClickModel($this->attractiveness);
        $this->stats = array_fill_keys(['sessions', 'searches', 'zero_results', 'clicks', 'carts', 'checkouts'], 0);

        foreach ($windows as $window) {
            $random = new Randomizer(new Xoshiro256StarStar(hash('sha256', "day|{$seed}|{$window['key']}", true)));

            for ($i = 0; $i < $sessionsPerDay; $i++) {
                $this->simulateSession($random, $i, $window, $search, $publisher, $eta, $noise, $inject);
            }
        }

        $published = $publisher->finish();

        $this->table(
            ['sesje', 'wyszukania', 'zero wyników', 'kliknięcia', 'koszyk', 'zakupy', 'opublikowane', 'czas [s]'],
            [[
                $this->stats['sessions'], $this->stats['searches'], $this->stats['zero_results'], $this->stats['clicks'],
                $this->stats['carts'], $this->stats['checkouts'], $published, round(microtime(true) - $started, 1),
            ]],
        );

        return self::SUCCESS;
    }

    /**
     * Okna symulacji. Dzień: [początek, koniec) z krzywą dobową i anomalią
     * w ostatniej godzinie ostatniego symulowanego dnia. Live: ostatnie 15 minut,
     * ruch jednostajny, anomalia w całym oknie.
     *
     * @return list<array{key: string, ordinal: int, from: CarbonImmutable, to: CarbonImmutable, curve: bool, affectedFrom: ?CarbonImmutable}>|null
     */
    private function windows(int $days, ?int $day, bool $inject): ?array
    {
        if ($this->option('live')) {
            $now = CarbonImmutable::now('UTC')->startOfSecond();
            $from = $now->subMinutes(self::LIVE_MINUTES);

            return [[
                'key' => 'live|'.$now->format('Y-m-d\TH:i:s'),
                'ordinal' => self::dayOrdinal($now),
                'from' => $from,
                'to' => $now,
                'curve' => false,
                'affectedFrom' => $inject ? $from : null,
            ]];
        }

        $asOfOption = $this->option('as-of');
        if ($asOfOption !== null && $asOfOption !== '') {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $asOfOption) !== 1) {
                return null;
            }
            $asOf = CarbonImmutable::createFromFormat('!Y-m-d', (string) $asOfOption, 'UTC');
        } else {
            $asOf = CarbonImmutable::now('UTC')->startOfDay();
        }

        $numbers = $day !== null ? [$day] : range(1, $days);
        $windows = [];
        foreach ($numbers as $n) {
            $from = $asOf->subDays($days - $n + 1);
            $to = $from->addDay();
            $windows[] = [
                'key' => $from->format('Y-m-d'),
                'ordinal' => self::dayOrdinal($from),
                'from' => $from,
                'to' => $to,
                'curve' => true,
                'affectedFrom' => $inject && $n === end($numbers) ? $to->subHour() : null,
            ];
        }

        return $windows;
    }

    /**
     * Jedna sesja. Generator dnia oddaje sesji tylko 32 bajty ziarna (stałe
     * zużycie), a sesja wyprowadza z nich osobne generatory: dla siebie, dla
     * każdego wyszukania, dla jego kliknięć i dla zakupu. Kolejność remisów
     * w ES zmienia się między wywołaniami (kopie shardów), więc liczba
     * kliknięć bywa różna — bez tego rozdzielenia przesuwałaby całą dalszą
     * sekwencję losowań i ID kolejnych zdarzeń nie byłyby powtarzalne.
     * Czasy wyszukań są planowane z góry, z generatora sesji.
     *
     * @param  array{key: string, ordinal: int, from: CarbonImmutable, to: CarbonImmutable, curve: bool, affectedFrom: ?CarbonImmutable}  $window
     */
    private function simulateSession(
        Randomizer $day,
        int $index,
        array $window,
        ProductSearchService $search,
        SimulatedEventPublisher $publisher,
        float $eta,
        float $noise,
        ?string $inject,
    ): void {
        $this->stats['sessions']++;

        $sessionSeed = $day->getBytes(32);
        $rng = static fn (string $part): Randomizer => new Randomizer(new Xoshiro256StarStar(hash('sha256', $sessionSeed.'|'.$part, true)));

        $session = $rng('session');
        $sessionId = self::uuid($session);
        $searches = $session->getInt(1, 3);
        $noisy = $session->getFloat(0, 1) < $noise;
        // Losowany zawsze, używany dopiero przy koszyku (koszyk wymaga logowania).
        $loggedInAs = self::USER_ID_BASE + $session->getInt(1, self::USER_POOL);
        $last = $window['to']->subSecond();

        $times = [$this->sessionStart($session, $window)];
        for ($s = 1; $s < $searches; $s++) {
            $next = $times[$s - 1]->addSeconds($session->getInt(30, 300));
            $times[] = $next->greaterThan($last) ? $last : $next;
        }

        $userId = null;
        /** @var array<int, array{id: int, seller_id: int, price_cents: int}> $carted */
        $carted = [];
        $lastEventAt = $times[0];

        $emit = function (string $type, CarbonImmutable $occurredAt, array $data, Randomizer $random) use ($publisher, $sessionId, &$lastEventAt): void {
            $publisher->publish(EventEnvelope::make(self::ulid($occurredAt, $random), $type, $occurredAt, 'session', $sessionId, 0, $data));
            $lastEventAt = $occurredAt->greaterThan($lastEventAt) ? $occurredAt : $lastEventAt;
        };

        foreach ($times as $s => $searchedAt) {
            $searchRandom = $rng("search|{$s}");
            $affected = $window['affectedFrom'] !== null && $searchedAt->greaterThanOrEqualTo($window['affectedFrom']);

            $raw = $affected && $inject === 'zero-results' && $searchRandom->getFloat(0, 1) < self::ZERO_RESULTS_SHARE
                ? $this->pool->drawOutOfCatalog($searchRandom)
                : $this->pool->draw($searchRandom);
            $query = mb_strtolower(trim($raw));
            $result = $this->search($search, $query);
            $searchId = self::ulid($searchedAt, $searchRandom);

            $data = [
                'search_id' => $searchId,
                'query' => $query,
                'query_raw' => $raw,
                'filters' => [],
                'total_hits' => $result['total'],
                'results' => $result['results'],
                'sort' => 'relevance',
            ];
            if ($userId !== null) {
                $data['user_id'] = $userId;
            }
            $emit('user.searched', $searchedAt, $data, $searchRandom);
            $this->stats['searches']++;
            if ($result['total'] === 0) {
                $this->stats['zero_results']++;
            }

            $clickRandom = $rng("clicks|{$s}");
            $positions = $this->clickModel->clicks(
                $result['results'],
                $clickRandom->getInt(0, PHP_INT_MAX),
                $eta,
                $noisy ? 1.0 : 0.0,
                $affected && $inject === 'ctr-drop' ? self::CTR_DROP_MULTIPLIER : 1.0,
            );

            // Kliknięcia i koszyk po wyszukaniu, przed następnym wyszukaniem i przed końcem okna.
            $until = isset($times[$s + 1]) ? $times[$s + 1]->subSecond() : $last;
            $at = $searchedAt;
            $advance = static function () use (&$at, $clickRandom, $until, $searchedAt): CarbonImmutable {
                $next = $at->addSeconds($clickRandom->getInt(5, 90));

                return $at = $next->greaterThan($until) ? ($until->lessThan($searchedAt) ? $searchedAt : $until) : $next;
            };

            foreach ($positions as $position) {
                $productId = $result['results'][$position - 1];
                $click = [
                    'search_id' => $searchId,
                    'product_id' => $productId,
                    'position' => $position,
                    'dedup_key' => "click:{$searchId}:{$productId}",
                    'query' => $query,
                ];
                if ($userId !== null) {
                    $click['user_id'] = $userId;
                }
                $emit('user.clicked_result', $advance(), $click, $clickRandom);
                $this->stats['clicks']++;

                if (isset($carted[$productId]) || $clickRandom->getFloat(0, 1) >= self::CART_FACTOR * ($this->attractiveness)($productId)) {
                    continue;
                }
                $offer = $this->cheapestOffer($productId);
                if ($offer === null) {
                    continue;
                }

                $userId = $loggedInAs;
                $carted[$productId] = $offer;
                $emit('user.added_to_cart', $advance(), [
                    'product_id' => $productId,
                    'offer_id' => $offer['id'],
                    'search_id' => $searchId,
                    'query' => $query,
                    'user_id' => $userId,
                ], $clickRandom);
                $this->stats['carts']++;
            }
        }

        $checkout = $rng('checkout');
        if ($carted !== [] && $checkout->getFloat(0, 1) < self::CHECKOUT_PROBABILITY) {
            // Jeden checkout = jedno zamówienie na sprzedawcę, jak w Orderingu.
            $sellers = count(array_unique(array_column($carted, 'seller_id')));
            $firstOrderId = self::ORDER_ID_BASE + $window['ordinal'] * self::ORDERS_PER_DAY + $index * self::ORDERS_PER_SESSION;
            $checkedOutAt = $lastEventAt->addSeconds($checkout->getInt(30, 300));

            $emit('user.checked_out', $checkedOutAt->greaterThan($last) ? $last : $checkedOutAt, [
                'order_ids' => range($firstOrderId + 1, $firstOrderId + min($sellers, self::ORDERS_PER_SESSION - 1)),
                'product_ids' => array_keys($carted),
                'amount_cents' => array_sum(array_column($carted, 'price_cents')),
                'user_id' => $userId,
            ], $checkout);
            $this->stats['checkouts']++;
        }
    }

    /**
     * Początek sesji: w dniu godzina wg krzywej dobowej, sekunda jednostajnie;
     * w trybie live jednostajnie w całym oknie.
     *
     * @param  array{from: CarbonImmutable, to: CarbonImmutable, curve: bool}  $window
     */
    private function sessionStart(Randomizer $random, array $window): CarbonImmutable
    {
        if (! $window['curve']) {
            $span = $window['to']->getTimestamp() - $window['from']->getTimestamp();

            return $window['from']->addSeconds($random->getInt(0, $span - 1));
        }

        $u = $random->getFloat(0, array_sum(self::HOUR_WEIGHTS));
        $hour = 0;
        foreach (self::HOUR_WEIGHTS as $hour => $weight) {
            if ($u < $weight) {
                break;
            }
            $u -= $weight;
        }

        return $window['from']->addSeconds($hour * 3600 + $random->getInt(0, 3599));
    }

    /**
     * Prawdziwy ranking, cache per fraza w obrębie wywołania (stały ranking w pętli otwartej).
     *
     * @return array{total: int, results: list<int>}
     */
    private function search(ProductSearchService $search, string $query): array
    {
        if (! isset($this->searchCache[$query])) {
            $result = $search->search(new SearchCriteria(
                q: $query,
                brands: [],
                categoryPath: null,
                priceMin: null,
                priceMax: null,
                inStock: null,
                sort: 'relevance',
                cursor: null,
                perPage: SearchedEventEmitter::TOP_RESULTS,
            ));

            $this->searchCache[$query] = [
                'total' => (int) $result->total['value'],
                'results' => array_map(
                    static fn (array $item): int => (int) $item['id'],
                    array_slice($result->items, 0, SearchedEventEmitter::TOP_RESULTS),
                ),
            ];
        }

        return $this->searchCache[$query];
    }

    /**
     * Najtańsza dostępna oferta produktu (ta sama definicja dostępności co w sklepie).
     *
     * @return array{id: int, seller_id: int, price_cents: int}|null
     */
    private function cheapestOffer(int $productId): ?array
    {
        if (! array_key_exists($productId, $this->offerCache)) {
            $offer = Offer::query()->listed()->where('product_id', $productId)
                ->orderBy('price_cents')->orderBy('id')
                ->first(['id', 'seller_id', 'price_cents']);

            $this->offerCache[$productId] = $offer === null ? null : [
                'id' => (int) $offer->id,
                'seller_id' => (int) $offer->seller_id,
                'price_cents' => (int) $offer->price_cents,
            ];
        }

        return $this->offerCache[$productId];
    }

    private static function dayOrdinal(CarbonImmutable $at): int
    {
        return (int) CarbonImmutable::create(2020, 1, 1, 0, 0, 0, 'UTC')->diffInDays($at->startOfDay(), true);
    }

    /**
     * UUID v4 z generatora przebiegu (deterministyczny dla ziarna), jak `mp_sid`.
     */
    private static function uuid(Randomizer $random): string
    {
        $bytes = $random->getBytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * ULID (48 bitów czasu w ms + 80 bitów losowych), jak `Str::ulid()` w UI,
     * ale z generatora przebiegu — te same ziarno i dzień dają te same ID.
     */
    private static function ulid(CarbonImmutable $at, Randomizer $random): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

        $time = '';
        $ms = $at->getTimestamp() * 1000 + intdiv($at->micro, 1000);
        for ($i = 0; $i < 10; $i++) {
            $time = $alphabet[$ms % 32].$time;
            $ms = intdiv($ms, 32);
        }

        $bits = '';
        foreach (str_split($random->getBytes(10)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $rand = '';
        foreach (str_split($bits, 5) as $chunk) {
            $rand .= $alphabet[bindec($chunk)];
        }

        return $time.$rand;
    }
}
