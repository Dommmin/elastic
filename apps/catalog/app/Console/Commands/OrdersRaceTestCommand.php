<?php

namespace App\Console\Commands;

use App\Support\ConcurrencyConflictTrace;
use App\Support\RaceTest\RaceFixture;
use App\Support\RaceTest\RaceOutcome;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ordering\Application\Order\RecordPayment;
use Ordering\Application\Order\RecordPaymentHandler;
use Symfony\Component\Process\Process;
use Throwable;

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
 * DLACZEGO fixture na wierszach (`RaceFixture`), a składanie i płatność
 * handlerami: dane startowe nie są mierzone, mierzona jest ścieżka sagi.
 * Sprzątanie idzie w `finally` i w obsłudze SIGINT/SIGTERM, więc przerwany
 * przebieg nie zostawia śmieci.
 * DLACZEGO konflikty z osobnego pliku: ponowienia (`RetryOnConflict`)
 * połykają konflikty wersji w innych procesach. Komenda ustawia
 * `LOG_CONCURRENCY_PATH` sobie i swoim procesom potomnym, a repozytoria
 * dopisują tam każdy konflikt (`ConcurrencyConflictTrace`).
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

    /** Ile czekamy na dokończenie sagi (joby z backoffem wracają po 5–120 s). */
    private const int DRAIN_TIMEOUT_SECONDS = 300;

    /** Zapas na start Laravela we wszystkich procesach kupujących przed wspólną chwilą startu. */
    private const int START_DELAY_BASE_MS = 2000;

    private const int START_DELAY_PER_BUYER_MS = 40;

    private ?RaceFixture $fixture = null;

    private ?string $conflictLog = null;

    /** @var list<Process> */
    private array $running = [];

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
        $startedAt = now();
        $this->startConflictLog($run);
        $this->trapSignals();
        $exit = self::FAILURE;

        try {
            $fixture = $this->fixture = RaceFixture::create($run, $plan['buyers'], $plan['stock']);
            $this->info("Fixture race-{$run}: oferta #{$fixture->offerId}, kupujących ".count($fixture->userIds).'.');

            $placements = $this->placeOrders($fixture);
            $this->drain($plan, fn (): bool => self::ordersInStatus($fixture, 'placed') === 0);

            $this->payAwaiting($payments, $fixture);
            $this->drain($plan, fn (): bool => self::activeReservations($fixture) === 0);

            $outcome = new RaceOutcome($plan['buyers'], $plan['stock'], self::ordersInStatus($fixture, 'paid'));
            $this->renderReport($fixture, $outcome, $placements, $startedAt);
            $exit = $this->verdict($outcome);
        } finally {
            // Błąd sprzątania nie może przykryć błędu przebiegu, więc `finish()` go nie rzuca.
            $cleaned = $this->finish();
        }

        return $cleaned ? $exit : self::FAILURE;
    }

    /**
     * @return array{buyers: int, stock: int, workers: int, connection: string}|null
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

        return $values + ['connection' => (string) config('queue.ordering_process_connection')];
    }

    /**
     * @param  array{buyers: int, stock: int, workers: int, connection: string}  $plan
     */
    private function renderPlan(array $plan): void
    {
        $this->table(['Parametr', 'Wartość'], [
            ['Kupujący (procesy)', (string) $plan['buyers']],
            ['Stan oferty', (string) $plan['stock']],
            ['Workery kolejki', (string) $plan['workers']],
            ['Połączenie kolejki', $plan['connection']],
            ['Oczekiwane opłacone', (string) RaceOutcome::expectedPaid($plan['buyers'], $plan['stock'])],
            ['Fixture', 'race-{run}-{n}@example.test'],
        ]);
    }

    /**
     * Plik konfliktów tego przebiegu. `putenv`, bo ślad czyta zmienną przy
     * każdym zapisie (działa mimo `config:cache`), a procesy potomne
     * dziedziczą ją jawnie w `artisanProcess`. Konflikty `catalog-worker`
     * tu nie trafiają, więc licznik to dolna granica, gdy ten worker
     * przejmuje część jobów.
     */
    private function startConflictLog(string $run): void
    {
        $this->conflictLog = storage_path("logs/race-{$run}.log");
        putenv(ConcurrencyConflictTrace::ENV.'='.$this->conflictLog);
    }

    /**
     * Ctrl+C w trakcie przebiegu: zatrzymaj procesy potomne, posprzątaj
     * fixture i wyjdź kodem 128 + numer sygnału.
     */
    private function trapSignals(): void
    {
        if (! extension_loaded('pcntl')) {
            return;
        }

        $this->trap([SIGINT, SIGTERM], function (int $signal): void {
            $this->warn('Przerwano sygnałem, sprzątam.');

            foreach ($this->running as $process) {
                $process->stop(5);
            }

            $this->finish();

            exit(128 + $signal);
        });
    }

    /**
     * Sprzątanie raz, bez rzucania: zwraca false i wypisuje ID fixture, gdy się nie udało.
     */
    private function finish(): bool
    {
        $fixture = $this->fixture;
        $this->fixture = null;
        $ok = true;

        if ($fixture !== null && $this->option('keep')) {
            $this->warn("Fixture race-{$fixture->run} zostaje (--keep): oferta #{$fixture->offerId}, log konfliktów {$this->conflictLog}.");
        } elseif ($fixture !== null) {
            try {
                $fixture->cleanUp();
                $this->info("Fixture race-{$fixture->run} posprzątane.");
            } catch (Throwable $e) {
                $ok = false;
                $this->error("Sprzątanie fixture race-{$fixture->run} nie powiodło się: {$e->getMessage()}");
                $this->error(sprintf(
                    'Do ręcznego usunięcia: users %s, seller #%d, product #%d, offer #%d.',
                    implode(',', $fixture->userIds),
                    $fixture->sellerId,
                    $fixture->productId,
                    $fixture->offerId,
                ));
            }
        }

        if ($this->conflictLog !== null && ! $this->option('keep')) {
            @unlink($this->conflictLog);
        }

        putenv(ConcurrencyConflictTrace::ENV);

        return $ok;
    }

    /**
     * @return array{placed: int, refused: array<string, int>, errors: list<string>, ms: list<float>}
     */
    private function placeOrders(RaceFixture $fixture): array
    {
        $at = (int) (microtime(true) * 1000)
            + self::START_DELAY_BASE_MS
            + self::START_DELAY_PER_BUYER_MS * count($fixture->userIds);

        $processes = [];

        foreach ($fixture->userIds as $userId) {
            $process = $this->artisanProcess(['orders:race-test:buyer', (string) $userId, (string) $fixture->offerId, "--at={$at}"]);
            $process->start();
            $processes[] = $process;
        }

        $this->running = $processes;
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

        $this->running = [];

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
            $this->running = [];

            for ($i = 0; $i < $plan['workers']; $i++) {
                $worker = $this->artisanProcess(['queue:work', $plan['connection'], '--stop-when-empty', '--sleep=0']);
                $worker->start();
                $this->running[] = $worker;
            }

            foreach ($this->running as $worker) {
                $worker->wait();
            }

            $this->running = [];

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
     */
    private function payAwaiting(RecordPaymentHandler $payments, RaceFixture $fixture): void
    {
        $orders = DB::table('orders')
            ->whereIn('buyer_user_id', $fixture->userIds)
            ->where('status', 'awaiting_payment')
            ->orderBy('id')
            ->get(['id', 'total_cents', 'currency']);

        foreach ($orders as $order) {
            $payments->handle(new RecordPayment(
                (int) $order->id,
                "race-{$fixture->run}-{$order->id}",
                (int) $order->total_cents,
                (string) $order->currency,
            ));
        }

        $this->info('Opłacono zamówień: '.$orders->count().'.');
    }

    /**
     * @param  array{placed: int, refused: array<string, int>, errors: list<string>, ms: list<float>}  $placements
     */
    private function renderReport(RaceFixture $fixture, RaceOutcome $outcome, array $placements, DateTimeInterface $startedAt): void
    {
        /** @var array<string, int> $statuses */
        $statuses = DB::table('orders')
            ->whereIn('buyer_user_id', $fixture->userIds)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($total): int => (int) $total)
            ->all();

        $rejected = $statuses['rejected'] ?? 0;
        $other = array_diff_key($statuses, ['paid' => true, 'rejected' => true]);
        $conflicts = $this->conflictCounts();
        $ms = $placements['ms'];
        sort($ms);

        $this->table(['Wynik', 'Wartość'], [
            ['Złożone zamówienia', (string) $placements['placed']],
            ['Odmowy przy składaniu', $placements['refused'] === [] ? '0' : self::counts($placements['refused'])],
            ['Błędy procesów kupujących', (string) count($placements['errors'])],
            ['Opłacone', (string) $outcome->paid],
            ['Odrzucone', (string) $rejected],
            ['W innym statusie', array_sum($other).($other === [] ? '' : ' ('.self::counts($other).')')],
            ['offer.stock końcowy', (string) DB::table('offers')->where('id', $fixture->offerId)->value('stock')],
            ['Aktywne rezerwacje', (string) self::activeReservations($fixture)],
            ['Overselling (opłacone − stock)', (string) $outcome->overselling()],
            ['Konflikty wersji (oferta / zamówienie)', "{$conflicts['offer']} / {$conflicts['order']}"],
            ['Złożenie p50 / p95 (ms)', sprintf('%.1f / %.1f', RaceOutcome::percentile($ms, 50), RaceOutcome::percentile($ms, 95))],
            ['failed_jobs od startu (wszystkie joby, nie tylko fixture)', (string) DB::table((string) config('queue.failed.table', 'failed_jobs'))
                ->where('failed_at', '>=', $startedAt)
                ->count()],
        ]);

        foreach ($placements['errors'] as $error) {
            $this->error("Kupujący: {$error}");
        }
    }

    private function verdict(RaceOutcome $outcome): int
    {
        $failure = $outcome->failure();

        if ($failure !== null) {
            $this->error($failure);

            return self::FAILURE;
        }

        $this->info("OK: opłacone {$outcome->paid}, overselling 0.");

        return self::SUCCESS;
    }

    private static function ordersInStatus(RaceFixture $fixture, string $status): int
    {
        return DB::table('orders')->whereIn('buyer_user_id', $fixture->userIds)->where('status', $status)->count();
    }

    private static function activeReservations(RaceFixture $fixture): int
    {
        return DB::table('offer_reservations')->where('offer_id', $fixture->offerId)->where('status', 'active')->count();
    }

    /**
     * @return array{offer: int, order: int}
     */
    private function conflictCounts(): array
    {
        $counts = ['offer' => 0, 'order' => 0];

        if ($this->conflictLog === null || ! is_file($this->conflictLog)) {
            return $counts;
        }

        $log = (string) file_get_contents($this->conflictLog);

        foreach (array_keys($counts) as $aggregate) {
            $counts[$aggregate] = substr_count($log, 'concurrency_conflict {"aggregate":"'.$aggregate.'"');
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
     * @param  list<string>  $arguments
     */
    private function artisanProcess(array $arguments): Process
    {
        $process = new Process(
            [PHP_BINARY, base_path('artisan'), ...$arguments],
            base_path(),
            [ConcurrencyConflictTrace::ENV => (string) $this->conflictLog],
        );
        $process->setTimeout(self::DRAIN_TIMEOUT_SECONDS * 2);

        return $process;
    }
}
