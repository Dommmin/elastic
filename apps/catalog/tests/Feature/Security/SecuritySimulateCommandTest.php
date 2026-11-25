<?php

use App\Analytics\Simulation\SimulatedEventPublisher;
use App\Models\Offer;
use App\Models\Product;
use App\Models\Seller;
use App\Security\ClientFingerprint;
use App\Security\Simulation\PopularitySource;
use App\Security\Simulation\SecurityScenario;
use App\Seller360\Simulation\ComplaintsCsvWriter;

/**
 * Symulator `security:simulate` (ETAP 15, Task 9). Publisher i źródło popularności podmienione fałszywkami
 * (bez RabbitMQ i ES), pliki w katalogu tymczasowym. Oprócz kształtu kopert test liczy reguły od nowa na
 * wygenerowanych danych: alert ma wypaść DOKŁADNIE dla zasadzonych przypadków, a nie dla bliskich pudeł ani tła.
 */
const SEC_SIM_NOW = '2026-11-25 12:00:00 UTC';
const SEC_SIM_P95 = 0.5;

beforeEach(function () {
    $this->travelTo(new DateTimeImmutable(SEC_SIM_NOW));
    config(['services.security.ip_pepper' => 'test-pepper']);
    app()->forgetInstance(ClientFingerprint::class);

    $this->dir = sys_get_temp_dir().'/security-sim-'.uniqid();
    mkdir($this->dir, 0775, true);
    config(['services.security.simulation_dir' => $this->dir.'/truth']);
    config(['seller360.simulation_dir' => $this->dir.'/seller360']);
    $this->csv = $this->dir.'/complaints.csv';
    $this->seller360Csv = $this->dir.'/seller360-complaints.csv';

    $this->publisher = new class extends SimulatedEventPublisher
    {
        /** @var list<array<string, mixed>> */
        public array $envelopes = [];

        public function publish(array $envelope): void
        {
            $this->envelopes[] = $envelope;
        }

        public function finish(): int
        {
            return count($this->envelopes);
        }
    };
    app()->instance(SimulatedEventPublisher::class, $this->publisher);

    // Popularność: 12 produktów wysoko (≥ p95), 25 nisko, 10 pośrodku (poza oboma zbiorami).
    $this->popularity = [];
    $seller = Seller::factory()->create();
    foreach (['high' => [12, 0.9], 'low' => [25, 0.1], 'mid' => [10, 0.4]] as [$count, $popularity]) {
        for ($i = 0; $i < $count; $i++) {
            $product = Product::factory()->create();
            Offer::factory()->create(['product_id' => $product->id, 'seller_id' => $seller->id, 'price_cents' => 10_000 + 1_000 * $i]);
            $this->popularity[$product->id] = $popularity;
        }
    }
    $this->source = new class($this->popularity) implements PopularitySource
    {
        /** @param array<int, float> $popularity */
        public function __construct(private readonly array $popularity) {}

        public function p95(): ?float
        {
            return SEC_SIM_P95;
        }

        public function productsAtLeast(float $min, int $limit): array
        {
            return array_slice(array_keys(array_filter($this->popularity, fn (float $p) => $p >= $min)), 0, $limit);
        }

        public function productsBelow(float $max, int $limit): array
        {
            return array_slice(array_keys(array_filter($this->popularity, fn (float $p) => $p < $max)), 0, $limit);
        }
    };
    app()->instance(PopularitySource::class, $this->source);

    // Syntetyczni sprzedawcy z ETAPU 13: 30 zwykłych, 2 z planted.json, 1 z reklamacjami SIM- (CSV ETAPU 13).
    $this->synthetic = Seller::factory()->count(30)->create(['synthetic' => true]);
    $this->etap13Planted = Seller::factory()->count(2)->create(['synthetic' => true]);
    $this->withSimComplaints = Seller::factory()->create(['synthetic' => true]);
    mkdir($this->dir.'/seller360', 0775, true);
    file_put_contents($this->dir.'/seller360/planted.json', json_encode([
        'planted' => [$this->etap13Planted[0]->id],
        'near_misses' => [['id' => $this->etap13Planted[1]->id, 'missing' => 'drop']],
    ]));
    file_put_contents($this->seller360Csv, "ticket_id,nip,created_at,category,status,body\nSIM-1-0000001,{$this->withSimComplaints->nip},2026-11-20T10:00:00+00:00,dostawa,open,Opóźnienie\n");
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->dir));
});

/** @param array<string, mixed> $options */
function runSecuritySimulate(object $test, array $options = []): void
{
    $test->artisan('security:simulate', $options + ['--seed' => 7, '--plant' => 2, '--complaints' => $test->csv, '--seller360-complaints' => $test->seller360Csv])
        ->assertSuccessful();
}

/** @return array{seed: int, generated_at: string, p95: float, cases: list<array<string, mixed>>} */
function securityTruth(object $test): array
{
    return json_decode((string) file_get_contents($test->dir.'/truth/planted-security.json'), true, flags: JSON_THROW_ON_ERROR);
}

/** @return list<array<string, string>> */
function securityComplaintRows(string $path): array
{
    $handle = fopen($path, 'r');
    $header = fgetcsv($handle, escape: '');
    $rows = [];
    while (($row = fgetcsv($handle, escape: '')) !== false) {
        $rows[] = array_combine($header, $row);
    }
    fclose($handle);

    return $rows;
}

/**
 * @param  list<array<string, mixed>>  $cases
 * @return list<string> klucze `encja|okno-start` dla reguły i typu
 */
function securityCaseKeys(array $cases, string $rule, string $type): array
{
    $keys = [];
    foreach ($cases as $case) {
        if ($case['rule'] === $rule && $case['type'] === $type) {
            $keys[] = $case['entity_id'].'|'.$case['window_start'];
        }
    }
    sort($keys);

    return $keys;
}

function securityPct(int $old, int $new): float
{
    return round(($new - $old) / $old * 100, 2);
}

test('to samo ziarno daje identyczny plik prawdy i identyczne koperty', function () {
    runSecuritySimulate($this);
    $first = $this->publisher->envelopes;
    $truth = file_get_contents($this->dir.'/truth/planted-security.json');
    $csv = file_get_contents($this->csv);

    $this->publisher->envelopes = [];
    runSecuritySimulate($this);

    expect($this->publisher->envelopes)->toBe($first)
        ->and(file_get_contents($this->dir.'/truth/planted-security.json'))->toBe($truth)
        ->and(file_get_contents($this->csv))->toBe($csv);

    $this->publisher->envelopes = [];
    runSecuritySimulate($this, ['--seed' => 8]);
    expect($this->publisher->envelopes)->not->toBe($first);
});

test('--plant=2 daje 2 zasadzone i 2 bliskie pudła na każdą z 5 reguł', function () {
    runSecuritySimulate($this);
    $truth = securityTruth($this);

    expect($truth['seed'])->toBe(7)
        ->and($truth['p95'])->toBe(SEC_SIM_P95)
        ->and($truth['generated_at'])->toBe('2026-11-25T12:00:00+00:00');

    $entityTypes = [
        'price-hug-threshold' => 'offer', 'fresh-account-5star' => 'user', 'review-ip-burst' => 'ip_hash',
        'price-bait-and-switch' => 'offer', 'complaints-spike' => 'seller',
    ];
    foreach ($entityTypes as $rule => $entityType) {
        foreach (['planted', 'near_miss'] as $type) {
            $cases = array_values(array_filter($truth['cases'], fn (array $c) => $c['rule'] === $rule && $c['type'] === $type));
            expect($cases)->toHaveCount(2, "{$rule} {$type}");
            foreach ($cases as $case) {
                expect($case['entity_type'])->toBe($entityType)
                    ->and($case['entity_id'])->toBeString()
                    ->and(new DateTimeImmutable($case['window_end']) >= new DateTimeImmutable($case['window_start']))->toBeTrue();
            }
        }
    }
    expect($truth['cases'])->toHaveCount(20);
});

test('każda koperta jest syntetyczna, w ostatnich --days dniach i bez surowego IP z pul TEST-NET', function () {
    runSecuritySimulate($this, ['--days' => 12]);
    $now = new DateTimeImmutable(SEC_SIM_NOW);
    $from = $now->modify('-12 days');
    $json = json_encode($this->publisher->envelopes, JSON_UNESCAPED_SLASHES);

    expect($this->publisher->envelopes)->not->toBeEmpty();
    foreach ($this->publisher->envelopes as $e) {
        $at = new DateTimeImmutable($e['occurred_at']);
        expect($e['data']['synthetic'])->toBeTrue()
            ->and($e['data']['simulation'])->toBe(SecurityScenario::SIMULATION)
            ->and($e['data']['simulation'])->toBe('security')
            ->and($at >= $from && $at <= $now)->toBeTrue($e['occurred_at'])
            ->and($e['type'])->toBeIn(['offer.price_changed', 'review.created', 'user.clicked_result']);
    }

    // Prefiks sieci (`192.0.2.0/24`) jest dozwolony, adres hosta nie.
    expect(preg_match('/\b(192\.0\.2|198\.51\.100|203\.0\.113)\.(?!0\/24)\d{1,3}\b/', $json))->toBe(0)
        ->and(preg_match('/2001:db8:[0-9a-f:]*::[0-9a-f]+(?!\/)/i', $json))->toBe(0);

    $fingerprint = app(ClientFingerprint::class);
    $reviews = array_filter($this->publisher->envelopes, fn (array $e) => $e['type'] === 'review.created');
    foreach ($reviews as $review) {
        expect($review['data']['client_ip_hash'])->toMatch('/^[0-9a-f]{16}$/')
            ->and($review['data']['client_ip_prefix'])->toMatch('#^(192\.0\.2\.0/24|198\.51\.100\.0/24|203\.0\.113\.0/24|2001:db8:[0-9a-f:]*/48)$#')
            ->and($review['data']['user_id'])->toBeGreaterThanOrEqual(SecurityScenario::USER_ID_BASE);
    }
    // Hasz zasadzonego IP to ten sam HMAC co w sklepie: burst na 192.0.2.10 ma hasz z ClientFingerprint.
    $burstHashes = array_column(array_filter(securityTruth($this)['cases'], fn (array $c) => $c['rule'] === 'review-ip-burst'), 'entity_id');
    expect($burstHashes)->toContain($fingerprint->hash('192.0.2.10'));
});

test('reklamacje mają prefiks SEC- i omijają sprzedawców z planted.json oraz z reklamacjami SIM- ETAPU 13', function () {
    runSecuritySimulate($this);
    $rows = securityComplaintRows($this->csv);
    $nips = array_unique(array_column($rows, 'nip'));

    expect($rows)->not->toBeEmpty();
    foreach ($rows as $row) {
        expect($row['ticket_id'])->toStartWith('SEC-7-');
    }
    expect($nips)->not->toContain($this->etap13Planted[0]->nip)
        ->not->toContain($this->etap13Planted[1]->nip)
        ->not->toContain($this->withSimComplaints->nip);

    $syntheticNips = $this->synthetic->pluck('nip')->all();
    foreach ($nips as $nip) {
        expect($syntheticNips)->toContain($nip);
    }
    foreach (securityTruth($this)['cases'] as $case) {
        if ($case['rule'] === 'complaints-spike') {
            expect($this->synthetic->pluck('id')->map(fn ($id) => (string) $id)->all())->toContain($case['entity_id']);
        }
    }
});

test('ComplaintsCsvWriter domyślnie nadal pisze SIM-', function () {
    $path = $this->dir.'/sim.csv';
    ComplaintsCsvWriter::write($path, 3, [['nip' => '1234567890', 'complaints' => [
        ['days_ago' => 1, 'seconds' => 10, 'category' => 'dostawa', 'status' => 'open', 'body' => 'x'],
    ]]], new DateTimeImmutable(SEC_SIM_NOW));

    expect(securityComplaintRows($path)[0]['ticket_id'])->toBe('SIM-3-0000001');
});

test('bez peppera symulator odmawia (hasz IP byłby pusty)', function () {
    config(['services.security.ip_pepper' => '']);
    app()->forgetInstance(ClientFingerprint::class);

    $this->artisan('security:simulate', ['--complaints' => $this->csv, '--seller360-complaints' => $this->seller360Csv])
        ->assertFailed();
    expect($this->publisher->envelopes)->toBeEmpty();
});

test('reguły policzone od nowa na danych strzelają dokładnie dla zasadzonych przypadków', function (int $seed, int $plant, int $days) {
    runSecuritySimulate($this, ['--seed' => $seed, '--plant' => $plant, '--days' => $days]);
    $truth = securityTruth($this);
    $cases = $truth['cases'];
    $events = $this->publisher->envelopes;
    usort($events, fn (array $a, array $b) => strcmp($a['occurred_at'], $b['occurred_at']));

    $productOfOffer = Offer::query()->pluck('product_id', 'id')->all();
    $utc = new DateTimeZone('UTC');
    $iso = fn (DateTimeImmutable $at) => $at->setTimezone($utc)->format(DATE_ATOM);

    // price-hug-threshold: 25 ≤ pct ≤ 30 i popularność ≥ p95.
    $hug = [];
    foreach ($events as $e) {
        if ($e['type'] !== 'offer.price_changed') {
            continue;
        }
        $pct = securityPct($e['data']['old_price_cents'], $e['data']['new_price_cents']);
        expect($e['data']['product_id'])->toBe($productOfOffer[(int) $e['aggregate']['id']]);
        if ($pct >= 25 && $pct <= 30 && ($this->popularity[$e['data']['product_id']] ?? 0) >= SEC_SIM_P95) {
            $hug[] = $e['aggregate']['id'].'|'.$iso(new DateTimeImmutable($e['occurred_at']));
        }
    }
    sort($hug);
    expect($hug)->toBe(securityCaseKeys($cases, 'price-hug-threshold', 'planted'));

    // Ceny każdej oferty tworzą łańcuch: stara cena zdarzenia = nowa cena poprzedniego.
    $last = [];
    foreach ($events as $e) {
        if ($e['type'] === 'offer.price_changed') {
            $id = $e['aggregate']['id'];
            if (isset($last[$id])) {
                expect($e['data']['old_price_cents'])->toBe($last[$id]);
            }
            $last[$id] = $e['data']['new_price_cents'];
        }
    }

    // fresh-account-5star: ocena 5 i konto < 24 h.
    $fresh = [];
    foreach ($events as $e) {
        if ($e['type'] === 'review.created' && $e['data']['rating'] === 5 && $e['data']['user_account_age_hours'] < 24) {
            $fresh[] = $e['data']['user_id'].'|'.$iso(new DateTimeImmutable($e['occurred_at']));
        }
    }
    sort($fresh);
    expect($fresh)->toBe(securityCaseKeys($cases, 'fresh-account-5star', 'planted'));

    // review-ip-burst: ≥ 5 różnych użytkowników na hasz w kubełku godzinowym UTC.
    $buckets = [];
    foreach ($events as $e) {
        if ($e['type'] === 'review.created') {
            $hour = (new DateTimeImmutable($e['occurred_at']))->setTimezone($utc)->format('Y-m-d\TH:00:00+00:00');
            $buckets[$e['data']['client_ip_hash'].'|'.$hour][$e['data']['user_id']] = true;
        }
    }
    $burst = array_keys(array_filter($buckets, fn (array $users) => count($users) >= 5));
    sort($burst);
    expect($burst)->toBe(securityCaseKeys($cases, 'review-ip-burst', 'planted'));
    foreach (securityCaseKeys($cases, 'review-ip-burst', 'near_miss') as $key) {
        expect(count($buckets[$key]))->toBe(4);
    }

    // price-bait-and-switch: obniżka ≤ -15, ≥ 20 kliknięć, dwie podwyżki, wszystko w 48 h, post-filtr.
    $byOffer = [];
    foreach ($events as $e) {
        $offer = match ($e['type']) {
            'offer.price_changed' => $e['aggregate']['id'],
            'user.clicked_result' => (string) $e['data']['offer_id'],
            default => null,
        };
        if ($offer !== null) {
            $byOffer[$offer][] = $e;
        }
    }
    $bait = [];
    foreach ($byOffer as $offer => $list) {
        foreach ($list as $i => $drop) {
            if ($drop['type'] !== 'offer.price_changed' || securityPct($drop['data']['old_price_cents'], $drop['data']['new_price_cents']) > -15) {
                continue;
            }
            $t0 = new DateTimeImmutable($drop['occurred_at']);
            $clicks = 0;
            $rises = [];
            foreach (array_slice($list, $i + 1) as $next) {
                if (new DateTimeImmutable($next['occurred_at']) > $t0->modify('+48 hours')) {
                    break;
                }
                if ($next['type'] === 'user.clicked_result') {
                    $clicks++;
                } elseif ($clicks >= 20 && securityPct($next['data']['old_price_cents'], $next['data']['new_price_cents']) > 0) {
                    $rises[] = $next;
                    if (count($rises) === 2) {
                        break;
                    }
                }
            }
            if (count($rises) < 2) {
                continue;
            }
            $steps = [$drop, ...$rises];
            $ok = true;
            foreach ($steps as $step) {
                $ok = $ok && abs(securityPct($step['data']['old_price_cents'], $step['data']['new_price_cents'])) <= 30;
            }
            if ($ok && $rises[1]['data']['new_price_cents'] * 10 >= $drop['data']['old_price_cents'] * 13) {
                $bait[] = $offer.'|'.$iso($t0);
            }
        }
    }
    sort($bait);
    expect($bait)->toBe(securityCaseKeys($cases, 'price-bait-and-switch', 'planted'));
    // Oferty przynęty są poza zbiorem ≥ p95, więc podwyżki ~28% nie odpalają price-hug.
    foreach (['planted', 'near_miss'] as $type) {
        foreach (securityCaseKeys($cases, 'price-bait-and-switch', $type) as $key) {
            $offer = (int) explode('|', $key)[0];
            expect($this->popularity[$productOfOffer[$offer]])->toBeLessThan(SEC_SIM_P95);
        }
    }

    // complaints-spike: doba ≥ średnia + 3σ z 7 poprzednich dni (populacyjne σ), ≥ 5, pierwsza reklamacja ≥ 7 dni wcześniej.
    $sellerByNip = Seller::query()->pluck('id', 'nip')->all();
    $daily = [];
    $first = [];
    foreach (securityComplaintRows($this->csv) as $row) {
        $day = (new DateTimeImmutable($row['created_at']))->setTimezone($utc)->setTime(0, 0);
        $seller = (string) $sellerByNip[$row['nip']];
        $key = $day->format('Y-m-d');
        $daily[$seller][$key] = ($daily[$seller][$key] ?? 0) + 1;
        $first[$seller] = min($first[$seller] ?? $key, $key);
    }
    $spikes = [];
    foreach ($daily as $seller => $days) {
        foreach ($days as $key => $count) {
            $day = new DateTimeImmutable($key.' 00:00:00 UTC');
            if ($first[$seller] > $day->modify('-7 days')->format('Y-m-d')) {
                continue;
            }
            $history = [];
            for ($k = 1; $k <= 7; $k++) {
                $history[] = $days[$day->modify("-{$k} days")->format('Y-m-d')] ?? 0;
            }
            $mean = array_sum($history) / 7;
            $std = sqrt(array_sum(array_map(fn (int $v) => ($v - $mean) ** 2, $history)) / 7);
            if ($count >= 5 && $count >= $mean + 3 * $std) {
                $spikes[] = $seller.'|'.$iso($day);
            }
        }
    }
    sort($spikes);
    expect($spikes)->toBe(securityCaseKeys($cases, 'complaints-spike', 'planted'));
})->with([
    'ziarno 1, P=3, 14 dni' => [1, 3, 14],
    'ziarno 2, P=5, 14 dni' => [2, 5, 14],
    'ziarno 9, P=5, 10 dni' => [9, 5, 10],
    'ziarno 42, P=4, 20 dni' => [42, 4, 20],
]);

test('uszkodzony planted.json ETAPU 13 przerywa symulację zamiast wyłączać wykluczenie', function () {
    file_put_contents($this->dir.'/seller360/planted.json', '{"planted": [1, 2');

    $this->artisan('security:simulate', ['--complaints' => $this->csv, '--seller360-complaints' => $this->seller360Csv])
        ->expectsOutputToContain('planted.json')
        ->assertFailed();
    expect($this->publisher->envelopes)->toBeEmpty()
        ->and(file_exists($this->csv))->toBeFalse();
});

test('planted.json bez list planted/near_misses też przerywa symulację', function () {
    file_put_contents($this->dir.'/seller360/planted.json', '{"planted": "5000001"}');

    $this->artisan('security:simulate', ['--complaints' => $this->csv, '--seller360-complaints' => $this->seller360Csv])
        ->assertFailed();
    expect($this->publisher->envelopes)->toBeEmpty();
});

test('CSV ETAPU 13 bez kolumny nip przerywa symulację', function () {
    file_put_contents($this->seller360Csv, "ticket_id,created_at\nSIM-1-0000001,2026-11-20T10:00:00+00:00\n");

    $this->artisan('security:simulate', ['--complaints' => $this->csv, '--seller360-complaints' => $this->seller360Csv])
        ->expectsOutputToContain('nip')
        ->assertFailed();
    expect($this->publisher->envelopes)->toBeEmpty();
});

test('brak planted.json i CSV ETAPU 13 przy syntetycznych sprzedawcach daje ostrzeżenia, a symulacja idzie dalej', function () {
    unlink($this->dir.'/seller360/planted.json');

    $this->artisan('security:simulate', ['--seed' => 7, '--plant' => 2, '--complaints' => $this->csv, '--seller360-complaints' => $this->dir.'/brak.csv'])
        ->expectsOutputToContain('Brak planted.json ETAPU 13')
        ->expectsOutputToContain('Brak CSV reklamacji ETAPU 13')
        ->assertSuccessful();
    expect($this->publisher->envelopes)->not->toBeEmpty();
});

test('plik prawdy zawiera liczbę dni symulacji', function () {
    runSecuritySimulate($this, ['--days' => 12]);

    expect(securityTruth($this)['days'])->toBe(12);
});
