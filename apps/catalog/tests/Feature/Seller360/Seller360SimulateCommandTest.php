<?php

use App\Analytics\Simulation\SimulatedEventPublisher;
use App\Models\Seller;
use App\Models\User;
use App\Seller360\Simulation\QueueBackpressure;
use Illuminate\Support\Facades\DB;
use SellerPortal\Domain\Seller\TaxId;

/**
 * Generator `seller360:simulate` (ETAP 13, Task 11). Publisher podmieniony fałszywką (bez RabbitMQ),
 * katalog wyników w katalogu tymczasowym — test nie nadpisuje `storage/app/seller360` żywego stosu.
 */
beforeEach(function () {
    $this->travelTo(new DateTimeImmutable('2026-11-22 12:00:00 UTC'));

    $this->dir = sys_get_temp_dir().'/seller360-sim-'.uniqid();
    config(['seller360.simulation_dir' => $this->dir]);
    $this->csv = $this->dir.'/complaints.csv';

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

    // Hamulec bez RabbitMQ: kolejki zawsze puste, zapamiętuje, o które pytano.
    $this->backpressure = new class extends QueueBackpressure
    {
        /** @var list<string> */
        public array $queues = [];

        protected function depth(string $queue): int
        {
            $this->queues[] = $queue;

            return 0;
        }
    };
    app()->instance(QueueBackpressure::class, $this->backpressure);
});

afterEach(function () {
    foreach (glob($this->dir.'/*') ?: [] as $file) {
        unlink($file);
    }
    @rmdir($this->dir);
});

/** @return array{planted: list<int>, near_misses: list<array{id: int, missing: string}>, region: string, phrase: string} */
function plantedJson(string $dir): array
{
    return json_decode((string) file_get_contents($dir.'/planted.json'), true, flags: JSON_THROW_ON_ERROR);
}

/** @return list<array<string, string>> */
function complaintRows(string $path): array
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

/** @return array<string, string> miasto → województwo */
function regionsByCity(): array
{
    $map = [];
    $handle = fopen(config('seller360.cities_csv'), 'r');
    fgetcsv($handle, escape: '');
    while (($row = fgetcsv($handle, escape: '')) !== false) {
        $map[$row[0]] = $row[1];
    }
    fclose($handle);

    return $map;
}

/**
 * Sesje koszyka i zamówienia sprzedawcy w oknach [now-30d, now) i [now-60d, now-30d), z kopert v2.
 *
 * @param  list<array<string, mixed>>  $envelopes
 * @return array{cart_prev: int, order_prev: int, cart_cur: int, order_cur: int}
 */
function salesWindows(array $envelopes, int $sellerId): array
{
    $now = new DateTimeImmutable('2026-11-22 12:00:00 UTC');
    $mid = $now->modify('-30 days');
    $from = $now->modify('-60 days');
    $sessions = ['cart_prev' => [], 'order_prev' => [], 'cart_cur' => [], 'order_cur' => []];

    foreach ($envelopes as $e) {
        $at = new DateTimeImmutable($e['occurred_at']);
        $window = $at >= $mid && $at < $now ? 'cur' : ($at >= $from && $at < $mid ? 'prev' : null);
        if ($window === null) {
            continue;
        }
        if ($e['type'] === 'user.added_to_cart' && ($e['data']['seller_id'] ?? null) === $sellerId) {
            $sessions["cart_{$window}"][$e['aggregate']['id']] = true;
        }
        if ($e['type'] === 'user.checked_out' && isset($e['data']['seller_amounts'][(string) $sellerId])) {
            $sessions["order_{$window}"][$e['aggregate']['id']] = true;
        }
    }

    return array_map('count', $sessions);
}

test('50 syntetycznych sprzedawców, 2 zasadzonych, 4 bliskie pudła i reklamacje w CSV', function () {
    $this->artisan('seller360:simulate', ['--sellers' => 50, '--billing' => true, '--plant' => 2, '--seed' => 3, '--complaints' => $this->csv])
        ->assertSuccessful();

    $sellers = Seller::query()->where('synthetic', true)->get();
    expect($sellers)->toHaveCount(50);
    foreach ($sellers as $seller) {
        expect(TaxId::fromString($seller->nip)->value())->toBe($seller->nip)
            ->and($seller->status)->toBe('active')
            ->and(User::query()->find($seller->user_id)?->synthetic)->toBeTrue();
    }

    $planted = plantedJson($this->dir);
    expect($planted['planted'])->toHaveCount(2)
        ->and($planted['near_misses'])->toHaveCount(4)
        ->and(array_column($planted['near_misses'], 'missing'))->toEqualCanonicalizing(['region', 'drop', 'overdue', 'complaint'])
        ->and($planted['region'])->toBe('mazowieckie')
        ->and($planted['phrase'])->toBe('uszkodzon*')
        ->and(array_keys($planted))->toBe(['planted', 'near_misses', 'region', 'phrase']);

    $nipById = $sellers->pluck('nip', 'id')->all();
    $rows = complaintRows($this->csv);
    $nipsInCsv = array_column($rows, 'nip');
    foreach ($planted['planted'] as $id) {
        expect($nipsInCsv)->toContain($nipById[$id]);
    }
});

test('zasadzeni spełniają wszystkie 4 warunki, każde bliskie pudło nie spełnia dokładnie jednego', function () {
    $this->artisan('seller360:simulate', ['--sellers' => 50, '--billing' => true, '--plant' => 2, '--seed' => 3, '--complaints' => $this->csv])
        ->assertSuccessful();

    $planted = plantedJson($this->dir);
    $regions = regionsByCity();
    $rows = complaintRows($this->csv);
    $envelopes = $this->publisher->envelopes;

    $check = function (int $id) use ($regions, $rows, $envelopes): array {
        $seller = Seller::query()->findOrFail($id);
        $sales = salesWindows($envelopes, $id);
        $prev = $sales['order_prev'] / max(1, $sales['cart_prev']);
        $cur = $sales['order_cur'] / max(1, $sales['cart_cur']);
        $drop = $prev > 0 ? ($prev - $cur) / $prev : 0.0;
        $damage = array_filter($rows, fn (array $r) => $r['nip'] === $seller->nip && str_contains(mb_strtolower($r['body']), 'uszkodz'));

        return [
            'region' => ($regions[$seller->city] ?? null) === 'mazowieckie',
            'drop' => $sales['cart_prev'] >= 40 && $drop >= 0.3 && $drop <= 0.5,
            'overdue' => DB::table('invoices')->where('seller_id', $id)->where('status', 'overdue')->exists(),
            'complaint' => $damage !== [],
            'drop_value' => $drop,
            'cart_prev' => $sales['cart_prev'],
        ];
    };

    foreach ($planted['planted'] as $id) {
        $c = $check($id);
        expect([$c['region'], $c['drop'], $c['overdue'], $c['complaint']])->toBe([true, true, true, true]);
    }

    foreach ($planted['near_misses'] as $miss) {
        $c = $check($miss['id']);
        foreach (['region', 'drop', 'overdue', 'complaint'] as $condition) {
            expect($c[$condition])->toBe($condition !== $miss['missing'], "bliskie pudło {$miss['missing']}, warunek {$condition}");
        }
        if ($miss['missing'] === 'drop') {
            expect($c['cart_prev'])->toBeGreaterThanOrEqual(40)
                ->and($c['drop_value'])->toBeLessThan(0.1);
        }
    }
});

test('fraza „uszkodz” tylko u zasadzonych i bliskich pudeł poza „complaint”, prawdziwi sprzedawcy bez reklamacji', function () {
    $real = Seller::factory()->create();

    $this->artisan('seller360:simulate', ['--sellers' => 50, '--billing' => true, '--plant' => 2, '--seed' => 3, '--complaints' => $this->csv])
        ->assertSuccessful();

    $planted = plantedJson($this->dir);
    $allowed = $planted['planted'];
    foreach ($planted['near_misses'] as $miss) {
        if ($miss['missing'] !== 'complaint') {
            $allowed[] = $miss['id'];
        }
    }
    $allowedNips = Seller::query()->whereIn('id', $allowed)->pluck('nip')->all();

    $rows = complaintRows($this->csv);
    expect($rows)->not->toBeEmpty()
        ->and(array_column($rows, 'nip'))->not->toContain($real->nip);

    $damageNips = [];
    foreach ($rows as $row) {
        expect(array_keys($row))->toBe(['ticket_id', 'nip', 'created_at', 'category', 'status', 'body']);
        if (preg_match('/uszkodz/iu', $row['body']) === 1) {
            $damageNips[$row['nip']] = true;
        }
    }
    expect(array_keys($damageNips))->toEqualCanonicalizing($allowedNips);
});

test('to samo ziarno daje identyczny planted.json i CSV (po --purge)', function () {
    $options = ['--sellers' => 50, '--billing' => true, '--plant' => 2, '--seed' => 3, '--complaints' => $this->csv];

    $this->artisan('seller360:simulate', $options)->assertSuccessful();
    $first = file_get_contents($this->dir.'/planted.json');
    $firstCsv = file_get_contents($this->csv);
    $firstNips = Seller::query()->where('synthetic', true)->orderBy('id')->pluck('nip', 'id')->all();

    $this->artisan('seller360:simulate', ['--purge' => true])->assertSuccessful();
    $this->artisan('seller360:simulate', $options)->assertSuccessful();

    expect(file_get_contents($this->dir.'/planted.json'))->toBe($first)
        ->and(file_get_contents($this->csv))->toBe($firstCsv)
        ->and(Seller::query()->where('synthetic', true)->orderBy('id')->pluck('nip', 'id')->all())->toBe($firstNips);
});

test('drugie uruchomienie bez --purge odmawia, gdy syntetyczni już są', function () {
    $options = ['--sellers' => 10, '--seed' => 3, '--complaints' => $this->csv];
    $this->artisan('seller360:simulate', $options)->assertSuccessful();

    $this->artisan('seller360:simulate', $options)->assertFailed();
    expect(Seller::query()->where('synthetic', true)->count())->toBe(10);
});

test('--sales: zdarzenia v2 dla prawdziwych sprzedawców i części syntetycznych, kwoty tylko tego sprzedawcy', function () {
    $real = Seller::factory()->create();

    $this->artisan('seller360:simulate', ['--sellers' => 50, '--sales' => true, '--plant' => 0, '--seed' => 3, '--complaints' => $this->csv])
        ->assertSuccessful();

    $envelopes = $this->publisher->envelopes;
    expect($envelopes)->not->toBeEmpty();

    $sellerIds = [];
    foreach ($envelopes as $e) {
        expect($e['version'])->toBe(2)
            ->and($e['aggregate']['type'])->toBe('session')
            ->and($e['type'])->toBeIn(['user.added_to_cart', 'user.checked_out']);
        if ($e['type'] === 'user.checked_out') {
            expect($e['data']['seller_amounts'])->toHaveCount(1)
                ->and(array_sum($e['data']['seller_amounts']))->toBe($e['data']['amount_cents']);
            $sellerIds[(int) array_key_first($e['data']['seller_amounts'])] = true;
        } else {
            $sellerIds[$e['data']['seller_id']] = true;
        }
    }

    expect($sellerIds)->toHaveKey($real->id);
    $synthetic = array_filter(array_keys($sellerIds), fn (int $id) => $id !== $real->id);
    expect(count($synthetic))->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(15);

    // Inne niż zasadzone: spadek konwersji < 0,1 (albo brak poprzedniego okna).
    $sales = salesWindows($envelopes, $real->id);
    $prev = $sales['order_prev'] / max(1, $sales['cart_prev']);
    $cur = $sales['order_cur'] / max(1, $sales['cart_cur']);
    expect($prev > 0 ? ($prev - $cur) / $prev : 0.0)->toBeLessThan(0.1);
});

test('--purge usuwa syntetycznych sprzedawców, użytkowników i faktury, prawdziwych zostawia, wypisuje komendę search', function () {
    $real = Seller::factory()->create();

    $this->artisan('seller360:simulate', ['--sellers' => 20, '--billing' => true, '--plant' => 1, '--seed' => 3, '--complaints' => $this->csv])
        ->assertSuccessful();
    expect(DB::table('invoices')->count())->toBeGreaterThan(0);

    $this->artisan('seller360:simulate', ['--purge' => true])
        ->expectsOutputToContain('seller360:purge-synthetic')
        ->assertSuccessful();

    expect(Seller::query()->where('synthetic', true)->count())->toBe(0)
        ->and(User::query()->where('synthetic', true)->count())->toBe(0)
        ->and(DB::table('invoices')->count())->toBe(0)
        ->and(DB::table('billing_accounts')->count())->toBe(0)
        ->and(Seller::query()->find($real->id))->not->toBeNull()
        ->and(file_exists($this->dir.'/planted.json'))->toBeFalse();
});

test('za mało sprzedawców na zasadzonych i bliskie pudła → błąd', function () {
    $this->artisan('seller360:simulate', ['--sellers' => 5, '--plant' => 2, '--complaints' => $this->csv])->assertFailed();
    expect(Seller::query()->where('synthetic', true)->count())->toBe(0);
});

test('hamulec: przed każdą paczką sprawdza kolejkę projektora, przed sprzedażą kolejkę analityki', function () {
    $this->artisan('seller360:simulate', ['--sellers' => 20, '--sales' => true, '--plant' => 1, '--seed' => 3, '--complaints' => $this->csv])
        ->assertSuccessful();

    expect($this->backpressure->queues)->toContain('search.seller360', 'search.analytics.ingest')
        ->and(array_search('search.seller360', $this->backpressure->queues))
        ->toBeLessThan(array_search('search.analytics.ingest', $this->backpressure->queues));
});

test('kolejka nie schodzi poniżej limitu w --max-wait: przerywa z instrukcją --purge', function () {
    $clock = new stdClass;
    $clock->now = 0.0;
    app()->instance(QueueBackpressure::class, new class(sleep: function (int $s) use ($clock): void {
        $clock->now += $s;
    }, clock: fn (): float => $clock->now) extends QueueBackpressure
    {
        protected function depth(string $queue): int
        {
            return 90_000;
        }
    });

    $this->artisan('seller360:simulate', ['--sellers' => 10, '--plant' => 0, '--seed' => 3, '--complaints' => $this->csv, '--max-wait' => 60])
        ->expectsOutputToContain('--purge')
        ->assertFailed();

    expect(Seller::query()->where('synthetic', true)->count())->toBe(0);
});

test('brak kolejki w RabbitMQ: czytelny błąd zamiast śladu stosu', function () {
    app()->instance(QueueBackpressure::class, new class extends QueueBackpressure
    {
        protected function depth(string $queue): int
        {
            throw new RuntimeException("Kolejka {$queue} nie istnieje w RabbitMQ (404).");
        }
    });

    $this->artisan('seller360:simulate', ['--sellers' => 10, '--plant' => 0, '--seed' => 3, '--complaints' => $this->csv])
        ->expectsOutputToContain('nie istnieje')
        ->assertFailed();
});
