<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Ordering\Application\Order\RecordPayment;
use Ordering\Application\Order\RecordPaymentHandler;
use Symfony\Component\Process\Process;

/**
 * Test współbieżności zamówień: N kupujących naraz na M ostatnich sztuk
 * jednej oferty, prawdziwe procesy, prawdziwa saga, prawdziwy Postgres.
 *
 * DLACZEGO tylko `pgsql`: SQLite blokuje całą bazę przy zapisie, więc
 * wyścigu o wersję oferty by nie było, a „zero oversellingu” nic by nie
 * znaczyło. Odmowa pada, zanim powstanie jakikolwiek wiersz.
 * DLACZEGO osobne procesy (`orders:race-test:buyer`, `queue:work`): każdy ma
 * własne połączenie z bazą, jak żądania HTTP i workery na produkcji; pętla
 * w jednym procesie złożyłaby zamówienia po kolei.
 * DLACZEGO fixture na wierszach, a składanie i płatność handlerami: dane
 * startowe nie są mierzone, mierzona jest ścieżka sagi. Fixture jest
 * oznaczone (`race-{run}-{n}@example.test`, slug `race-{run}`) i sprzątane
 * w `finally` po jego ID, więc przerwany przebieg nie zostawia śmieci
 * i nie dotyka cudzych danych.
 * DLACZEGO konflikty z logu: ponowienia (`RetryOnConflict`) połykają
 * konflikty wersji w innych procesach; repozytoria zapisują każdy do kanału
 * `concurrency`, a tu liczymy wpisy dopisane w trakcie przebiegu.
 */
class OrdersRaceTestCommand extends Command
{
    protected $signature = 'orders:race-test
        {--buyers=50 : Liczba równoległych kupujących (procesów)}
        {--stock=10 : Stan oferty, o który walczą}
        {--workers=4 : Liczba workerów kolejki sagi}
        {--keep : Nie sprzątaj fixture po przebiegu}
        {--dry-run : Wypisz plan i zakończ, bez bazy i procesów}';

    protected $description = 'Test współbieżności: równolegli kupujący na ostatnie sztuki oferty (tylko Postgres)';

    private const int PRICE_CENTS = 1000;

    private const string CURRENCY = 'PLN';

    /** Ile czekamy na dokończenie sagi (joby z backoffem wracają po 5–120 s). */
    private const int DRAIN_TIMEOUT_SECONDS = 300;

    /** Zapas na start Laravela we wszystkich procesach kupujących przed wspólną chwilą startu. */
    private const int START_DELAY_BASE_MS = 2000;

    private const int START_DELAY_PER_BUYER_MS = 40;

    public function handle(RecordPaymentHandler $payments): int
    {
        $plan = $this->plan();

        if ($plan === null) {
            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->renderPlan($plan);

            return self::SUCCESS;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver !== 'pgsql') {
            $this->error("orders:race-test wymaga Postgresa (pgsql), a połączenie to {$driver}. Uruchom w kontenerze catalog-app.");

            return self::FAILURE;
        }

        $this->renderPlan($plan);

        $run = Str::lower(Str::random(8));
        $fixture = null;
        $startedAt = now();
        $conflictLog = $this->conflictLogOffset();

        try {
            $fixture = $this->createFixture($run, $plan);
            $this->info("Fixture race-{$run}: oferta #{$fixture['offer']}, kupujących ".count($fixture['users']).'.');

            $placements = $this->placeOrders($fixture);
            $this->drain($plan, fn (): bool => $this->ordersInStatus($fixture, 'placed') === 0);

            $this->payAwaiting($payments, $fixture, $run);
            $this->drain($plan, fn (): bool => $this->activeReservations($fixture) === 0);

            $report = $this->report($plan, $fixture, $placements, $conflictLog, $startedAt);
            $this->renderReport($report);

            return $this->verdict($plan, $report);
        } finally {
            if ($fixture !== null && ! $this->option('keep')) {
                $this->cleanUp($fixture);
                $this->info("Fixture race-{$run} posprzątane.");
            } elseif ($fixture !== null) {
                $this->warn("Fixture race-{$run} zostaje (--keep): oferta #{$fixture['offer']}.");
            }
        }
    }

    /**
     * @return array{buyers: int, stock: int, workers: int, connection: string, expected_paid: int}|null
     */
    private function plan(): ?array
    {
        $values = [];

        foreach (['buyers', 'stock', 'workers'] as $name) {
            $raw = (string) $this->option($name);

            if (! ctype_digit($raw) || (int) $raw < 1) {
                $this->error("--{$name} musi być dodatnią liczbą całkowitą, podano „{$raw}”.");

                return null;
            }

            $values[$name] = (int) $raw;
        }

        return $values + [
            'connection' => (string) config('queue.ordering_process_connection'),
            'expected_paid' => min($values['buyers'], $values['stock']),
        ];
    }

    /**
     * @param  array{buyers: int, stock: int, workers: int, connection: string, expected_paid: int}  $plan
     */
    private function renderPlan(array $plan): void
    {
        $this->table(['Parametr', 'Wartość'], [
            ['Kupujący (procesy)', (string) $plan['buyers']],
            ['Stan oferty', (string) $plan['stock']],
            ['Workery kolejki', (string) $plan['workers']],
            ['Połączenie kolejki', $plan['connection']],
            ['Oczekiwane opłacone', (string) $plan['expected_paid']],
            ['Fixture', 'race-{run}-{n}@example.test'],
        ]);
    }

    /**
     * @param  array{buyers: int, stock: int}  $plan
     * @return array{users: list<int>, seller: int, product: int, offer: int}
     */
    private function createFixture(string $run, array $plan): array
    {
        return DB::transaction(function () use ($run, $plan): array {
            $now = now();
            $password = Hash::make(Str::random(40));

            $users = [];

            for ($n = 1; $n <= $plan['buyers']; $n++) {
                $users[] = (int) DB::table('users')->insertGetId([
                    'name' => "Race {$run} #{$n}",
                    'email' => "race-{$run}-{$n}@example.test",
                    'email_verified_at' => $now,
                    'password' => $password,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // Sprzedawca bez konta: nikt z fixture nie kupuje „własnej” oferty.
            $seller = (int) DB::table('sellers')->insertGetId([
                'name' => "Race {$run}",
                'slug' => "race-{$run}",
                'status' => 'active',
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $product = (int) DB::table('products')->insertGetId([
                'name' => "Race test {$run}",
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $offer = (int) DB::table('offers')->insertGetId([
                'product_id' => $product,
                'seller_id' => $seller,
                'price_cents' => self::PRICE_CENTS,
                'currency' => self::CURRENCY,
                'stock' => $plan['stock'],
                'condition' => 'new',
                'shipping_days' => 1,
                'status' => 'published',
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return ['users' => $users, 'seller' => $seller, 'product' => $product, 'offer' => $offer];
        });
    }

    /**
     * @param  array{users: list<int>, offer: int}  $fixture
     * @return array{placed: int, refused: array<string, int>, errors: list<string>, ms: list<float>}
     */
    private function placeOrders(array $fixture): array
    {
        $at = (int) (microtime(true) * 1000)
            + self::START_DELAY_BASE_MS
            + self::START_DELAY_PER_BUYER_MS * count($fixture['users']);

        $processes = [];

        foreach ($fixture['users'] as $userId) {
            $process = $this->artisanProcess(['orders:race-test:buyer', (string) $userId, (string) $fixture['offer'], "--at={$at}"]);
            $process->start();
            $processes[] = $process;
        }

        $this->info('Kupujący wystartowali: '.count($processes).' procesów.');

        $placements = ['placed' => 0, 'refused' => [], 'errors' => [], 'ms' => []];

        foreach ($processes as $process) {
            $process->wait();
            $lines = preg_split('/\R/', trim($process->getOutput())) ?: [];
            $result = json_decode((string) end($lines), true);

            if (! $process->isSuccessful() || ! is_array($result)) {
                $placements['errors'][] = Str::limit(trim($process->getErrorOutput().' '.$process->getOutput()), 300);

                continue;
            }

            $placements['ms'][] = (float) $result['ms'];

            if ($result['result'] === 'placed') {
                $placements['placed']++;
            } else {
                $placements['refused'][$result['reason']] = ($placements['refused'][$result['reason']] ?? 0) + 1;
            }
        }

        return $placements;
    }

    /**
     * Uruchamia workery do pustej kolejki, aż warunek sagi będzie spełniony.
     * Kilka rund, bo job po konflikcie wraca z backoffem, a `--stop-when-empty`
     * kończy workera, gdy żaden job nie jest jeszcze dostępny.
     *
     * @param  array{workers: int, connection: string}  $plan
     * @param  callable(): bool  $done
     */
    private function drain(array $plan, callable $done): void
    {
        $deadline = microtime(true) + self::DRAIN_TIMEOUT_SECONDS;

        do {
            $workers = [];

            for ($i = 0; $i < $plan['workers']; $i++) {
                $worker = $this->artisanProcess(['queue:work', $plan['connection'], '--stop-when-empty', '--sleep=0']);
                $worker->start();
                $workers[] = $worker;
            }

            foreach ($workers as $worker) {
                $worker->wait();
            }

            if ($done()) {
                return;
            }

            sleep(1);
        } while (microtime(true) < $deadline);

        $this->warn('Saga nie skończyła się w '.self::DRAIN_TIMEOUT_SECONDS.' s; raport pokaże stan pośredni.');
    }

    /**
     * Płatność tą samą komendą co webhook bramki (bez HTTP i podpisu: to nie
     * one są tu mierzone).
     *
     * @param  array{users: list<int>}  $fixture
     */
    private function payAwaiting(RecordPaymentHandler $payments, array $fixture, string $run): void
    {
        $orders = DB::table('orders')
            ->whereIn('buyer_user_id', $fixture['users'])
            ->where('status', 'awaiting_payment')
            ->orderBy('id')
            ->get(['id', 'total_cents', 'currency']);

        foreach ($orders as $order) {
            $payments->handle(new RecordPayment(
                (int) $order->id,
                "race-{$run}-{$order->id}",
                (int) $order->total_cents,
                (string) $order->currency,
            ));
        }

        $this->info('Opłacono zamówień: '.$orders->count().'.');
    }

    /**
     * @param  array{stock: int}  $plan
     * @param  array{users: list<int>, offer: int}  $fixture
     * @param  array{placed: int, refused: array<string, int>, errors: list<string>, ms: list<float>}  $placements
     * @param  array{path: string, offset: int}  $conflictLog
     * @return array<string, mixed>
     */
    private function report(array $plan, array $fixture, array $placements, array $conflictLog, \DateTimeInterface $startedAt): array
    {
        $statuses = DB::table('orders')
            ->whereIn('buyer_user_id', $fixture['users'])
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($total): int => (int) $total)
            ->all();

        $paid = $statuses['paid'] ?? 0;
        $rejected = $statuses['rejected'] ?? 0;
        $ms = $placements['ms'];
        sort($ms);

        return [
            'placed' => $placements['placed'],
            'refused' => $placements['refused'],
            'errors' => $placements['errors'],
            'paid' => $paid,
            'rejected' => $rejected,
            'other' => array_sum($statuses) - $paid - $rejected,
            'other_statuses' => array_diff_key($statuses, ['paid' => true, 'rejected' => true]),
            'stock' => (int) DB::table('offers')->where('id', $fixture['offer'])->value('stock'),
            'active_reservations' => $this->activeReservations($fixture),
            'overselling' => $paid - $plan['stock'],
            'conflicts' => $this->conflictsSince($conflictLog),
            'p50' => self::percentile($ms, 50),
            'p95' => self::percentile($ms, 95),
            'failed_jobs' => DB::table((string) config('queue.failed.table', 'failed_jobs'))
                ->where('failed_at', '>=', $startedAt)
                ->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderReport(array $report): void
    {
        $refused = $report['refused'] === [] ? '0' : self::counts($report['refused']);
        $other = self::counts($report['other_statuses']);

        $this->table(['Wynik', 'Wartość'], [
            ['Złożone zamówienia', (string) $report['placed']],
            ['Odmowy przy składaniu', $refused],
            ['Błędy procesów kupujących', (string) count($report['errors'])],
            ['Opłacone', (string) $report['paid']],
            ['Odrzucone', (string) $report['rejected']],
            ['W innym statusie', $report['other'].($other === '' ? '' : " ({$other})")],
            ['offer.stock końcowy', (string) $report['stock']],
            ['Aktywne rezerwacje', (string) $report['active_reservations']],
            ['Overselling (opłacone − stock)', (string) $report['overselling']],
            ['Konflikty wersji (oferta / zamówienie)', "{$report['conflicts']['offer']} / {$report['conflicts']['order']}"],
            ['Złożenie p50 / p95 (ms)', sprintf('%.1f / %.1f', $report['p50'], $report['p95'])],
            ['Nieudane joby (failed_jobs)', (string) $report['failed_jobs']],
        ]);

        foreach ($report['errors'] as $error) {
            $this->error("Kupujący: {$error}");
        }
    }

    /**
     * @param  array{stock: int, expected_paid: int}  $plan
     * @param  array<string, mixed>  $report
     */
    private function verdict(array $plan, array $report): int
    {
        if ($report['overselling'] > 0) {
            $this->error("OVERSELLING: opłacono {$report['paid']} przy stanie {$plan['stock']}.");

            return self::FAILURE;
        }

        if ($report['paid'] !== $plan['expected_paid']) {
            $this->error("Opłacone {$report['paid']}, oczekiwane {$plan['expected_paid']}.");

            return self::FAILURE;
        }

        $this->info("OK: opłacone {$report['paid']}, overselling 0.");

        return self::SUCCESS;
    }

    /**
     * Kasuje wyłącznie wiersze fixture, po ID z `createFixture`. Query
     * Builder, a nie modele: modele z outboxem przy kasowaniu wysłałyby
     * zdarzenia o danych, których nikt poza testem nie widział.
     *
     * @param  array{users: list<int>, seller: int, product: int, offer: int}  $fixture
     */
    private function cleanUp(array $fixture): void
    {
        DB::transaction(function () use ($fixture): void {
            $orderIds = DB::table('orders')->whereIn('buyer_user_id', $fixture['users'])->pluck('id')->all();

            foreach (['order_events', 'order_lines', 'order_refunds'] as $table) {
                DB::table($table)->whereIn('order_id', $orderIds)->delete();
            }

            DB::table('orders')->whereIn('id', $orderIds)->delete();
            DB::table('cart_items')->whereIn('user_id', $fixture['users'])->delete();

            foreach (['offer_reservations', 'offer_price_changes', 'cart_items'] as $table) {
                DB::table($table)->where('offer_id', $fixture['offer'])->delete();
            }

            DB::table('outbox')
                ->where(fn ($q) => $q->where('aggregate_type', 'offer')->where('aggregate_id', (string) $fixture['offer']))
                ->orWhere(fn ($q) => $q->where('aggregate_type', 'product')->where('aggregate_id', (string) $fixture['product']))
                ->orWhere(fn ($q) => $q->where('aggregate_type', 'seller')->where('aggregate_id', (string) $fixture['seller']))
                ->delete();

            DB::table('offers')->where('id', $fixture['offer'])->delete();
            DB::table('products')->where('id', $fixture['product'])->delete();
            DB::table('sellers')->where('id', $fixture['seller'])->delete();
            DB::table('users')->whereIn('id', $fixture['users'])->delete();
        });
    }

    /**
     * @param  array{users: list<int>}  $fixture
     */
    private function ordersInStatus(array $fixture, string $status): int
    {
        return DB::table('orders')->whereIn('buyer_user_id', $fixture['users'])->where('status', $status)->count();
    }

    /**
     * @param  array{offer: int}  $fixture
     */
    private function activeReservations(array $fixture): int
    {
        return DB::table('offer_reservations')->where('offer_id', $fixture['offer'])->where('status', 'active')->count();
    }

    /**
     * @return array{path: string, offset: int}
     */
    private function conflictLogOffset(): array
    {
        $path = (string) config('logging.channels.concurrency.path');
        clearstatcache(true, $path);

        return ['path' => $path, 'offset' => is_file($path) ? (int) filesize($path) : 0];
    }

    /**
     * Liczy wpisy dopisane od startu przebiegu. Inne procesy na tej samej
     * bazie (np. `catalog-worker`) też tu piszą, więc to górna granica.
     *
     * @param  array{path: string, offset: int}  $log
     * @return array{offer: int, order: int}
     */
    private function conflictsSince(array $log): array
    {
        $counts = ['offer' => 0, 'order' => 0];
        clearstatcache(true, $log['path']);

        if (! is_file($log['path'])) {
            return $counts;
        }

        $appended = (string) file_get_contents($log['path'], offset: $log['offset']);

        foreach (array_keys($counts) as $aggregate) {
            $counts[$aggregate] = substr_count($appended, 'concurrency_conflict {"aggregate":"'.$aggregate.'"');
        }

        return $counts;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private static function counts(array $counts): string
    {
        $parts = [];

        foreach ($counts as $label => $n) {
            $parts[] = "{$label}: {$n}";
        }

        return implode(', ', $parts);
    }

    /**
     * Percentyl metodą najbliższej rangi: przy kilkudziesięciu próbkach
     * interpolacja udawałaby precyzję, której pomiar nie ma.
     *
     * @param  list<float>  $sorted
     */
    private static function percentile(array $sorted, int $p): float
    {
        if ($sorted === []) {
            return 0.0;
        }

        $rank = (int) ceil($p / 100 * count($sorted));

        return $sorted[max(0, $rank - 1)];
    }

    /**
     * @param  list<string>  $arguments
     */
    private function artisanProcess(array $arguments): Process
    {
        $process = new Process([PHP_BINARY, base_path('artisan'), ...$arguments], base_path());
        $process->setTimeout(self::DRAIN_TIMEOUT_SECONDS * 2);

        return $process;
    }
}
