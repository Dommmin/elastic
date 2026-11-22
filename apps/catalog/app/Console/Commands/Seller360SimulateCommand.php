<?php

namespace App\Console\Commands;

use App\Analytics\Simulation\SimulatedEventPublisher;
use App\Seller360\Simulation\BillingScenario;
use App\Seller360\Simulation\ComplaintsCsvWriter;
use App\Seller360\Simulation\PlantedSellers;
use App\Seller360\Simulation\QueueBackpressure;
use App\Seller360\Simulation\QueueBackpressureFailed;
use App\Seller360\Simulation\SalesEvents;
use App\Seller360\Simulation\SyntheticSellers;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Generator danych seller-360 (ETAP 13, Task 11): syntetyczni sprzedawcy, faktury, reklamacje (CSV),
 * zdarzenia sprzedaży z datą wsteczną oraz zasadzeni sprzedawcy z prawdą podstawową (`planted.json`),
 * którą dowód z Task 12 sprawdza z precyzją i czułością 1,0.
 *
 * Determinizm: jeden generator `Mt19937($seed)` prowadzi wszystko w stałej kolejności — role, potem
 * po kolei każdy sprzedawca (miasto, nazwa, faktury, reklamacje, czy ma sprzedaż), potem zdarzenia.
 * Plan każdego sprzedawcy losujemy zawsze w całości, niezależnie od flag, a flagi decydują tylko, co
 * zapisać. To samo ziarno i te same opcje dają identyczne `planted.json`, CSV i sprzedawców.
 *
 * Zasadzeni i bliskie pudła dostają faktury i sprzedaż zawsze (bez nich nie byłoby prawdy
 * podstawowej); `--billing` dokłada faktury pozostałym, `--sales` sprzedaż prawdziwym sprzedawcom
 * i około 10% syntetycznych.
 *
 * Zapis w paczkach po 500 sprzedawców na transakcję, drogą domenową (outbox `seller.*` i `billing.*`).
 * Przed każdą paczką i co 1000 zdarzeń sprzedaży hamulec (`QueueBackpressure`) czeka, aż kolejka
 * konsumenta zejdzie poniżej 50 tys. — kolejki mają limit 100 tys. z `drop-head` do DLQ.
 * Catalog nie pisze do ES, więc `--purge` czyści tylko bazę i wypisuje komendę dla search.
 */
class Seller360SimulateCommand extends Command
{
    protected $signature = 'seller360:simulate
        {--seed=1 : Ziarno generatora}
        {--sellers=0 : Liczba syntetycznych sprzedawców}
        {--billing : Faktury z ostatnich 90 dni dla wszystkich syntetycznych (ok. 15% z zaległością)}
        {--complaints=storage/app/seller360/complaints.csv : Ścieżka CSV z reklamacjami (względna wobec katalogu aplikacji)}
        {--sales : Zdarzenia sprzedaży w dwóch oknach po 30 dni: prawdziwi sprzedawcy i ok. 10% syntetycznych}
        {--plant=5 : Zasadzeni sprzedawcy (plus 4 bliskie pudła)}
        {--max-wait=900 : Maksymalne czekanie [s] na zejście kolejki konsumenta poniżej limitu; potem przerwanie}
        {--purge : Usuń syntetycznych sprzedawców, użytkowników i faktury, wypisz komendę czyszczącą search}';

    protected $description = 'Generuje syntetycznych sprzedawców, faktury, reklamacje i sprzedaż z prawdą podstawową (seller-360)';

    public const MAX_SELLERS = 20_000;

    private const BATCH = 500;

    private const SALES_SHARE = 0.1;

    private const PROJECTOR_QUEUE = 'search.seller360';

    private const ANALYTICS_QUEUE = 'search.analytics.ingest';

    private const SALES_CHECK_EVERY = 1_000;

    private float $waited = 0.0;

    private int $maxWait = QueueBackpressure::DEFAULT_MAX_WAIT_SECONDS;

    public const SEARCH_PURGE_COMMAND = 'docker compose exec seller360-consumer php bin/console seller360:purge-synthetic';

    public function handle(SyntheticSellers $synthetic, BillingScenario $billing, SimulatedEventPublisher $publisher, QueueBackpressure $backpressure): int
    {
        $started = microtime(true);
        $this->waited = 0.0;
        $dir = (string) config('seller360.simulation_dir');

        if ($this->option('purge')) {
            return $this->purge($dir);
        }

        $seed = (int) $this->option('seed');
        $count = (int) $this->option('sellers');
        $plant = (int) $this->option('plant');

        if ($count < 0 || $count > self::MAX_SELLERS || $plant < 0) {
            $this->error('--sellers musi być w zakresie 0..'.self::MAX_SELLERS.', --plant >= 0.');

            return self::FAILURE;
        }
        if (DB::table('sellers')->where('synthetic', true)->exists()) {
            $this->error('W bazie są już syntetyczni sprzedawcy. Najpierw: php artisan seller360:simulate --purge');

            return self::FAILURE;
        }

        $random = new Randomizer(new Mt19937($seed));
        try {
            $roles = PlantedSellers::assign($random, $count, $plant);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $cities = SyntheticSellers::cities((string) config('seller360.cities_csv'));
        /** @var array{damage: list<array{category: string, body: string}>, other: list<array{category: string, body: string}>} $templates */
        $templates = require resource_path('seller360/complaint-templates.php');

        $plans = [];
        for ($i = 0; $i < $count; $i++) {
            $role = $roles[$i] ?? null;
            $plans[] = [
                'role' => $role,
                'seller' => SyntheticSellers::draw($random, $i, $role, $cities),
                'billing' => BillingScenario::draw($random, $role),
                'complaints' => ComplaintsCsvWriter::draw($random, $role, $templates),
                'sales' => $role !== null || $random->getFloat(0, 1) < self::SALES_SHARE,
            ];
        }

        $this->maxWait = max(1, (int) $this->option('max-wait'));
        try {
            $persisted = $this->persist($plans, $synthetic, $billing, $backpressure, $random, $now, $started);
        } catch (QueueBackpressureFailed $e) {
            return $this->abort($e, $backpressure);
        }
        $persistedAt = microtime(true);

        $path = $this->absolute((string) $this->option('complaints'));
        $complaintRows = ComplaintsCsvWriter::write($path, $seed, array_map(
            fn (array $plan): array => ['nip' => $plan['seller']['nip'], 'complaints' => $plan['complaints']],
            $plans,
        ), $now);

        $planted = [];
        $nearMisses = [];
        foreach ($plans as $plan) {
            if ($plan['role'] === PlantedSellers::ROLE_PLANTED) {
                $planted[] = $plan['seller']['id'];
            } elseif ($plan['role'] !== null) {
                $nearMisses[] = ['id' => $plan['seller']['id'], 'missing' => (string) PlantedSellers::missing($plan['role'])];
            }
        }
        PlantedSellers::write($dir.'/planted.json', $planted, $nearMisses);

        try {
            [$salesSellers, $events] = $this->publishSales($plans, $publisher, $backpressure, $random, $now);
        } catch (QueueBackpressureFailed $e) {
            return $this->abort($e, $backpressure);
        }
        $backpressure->close();

        $this->table(
            ['sprzedawcy', 'z fakturami', 'reklamacje', 'sprzedawcy ze sprzedażą', 'zdarzenia', 'zapis [s]', 'czekanie na kolejki [s]', 'razem [s]'],
            [[$count, $persisted, $complaintRows, $salesSellers, $events, round($persistedAt - $started, 1), round($this->waited, 1), round(microtime(true) - $started, 1)]],
        );
        $this->line("CSV: {$path}");
        $this->line("Prawda podstawowa: {$dir}/planted.json (zasadzeni: ".count($planted).', bliskie pudła: '.count($nearMisses).')');

        return self::SUCCESS;
    }

    /**
     * @param  list<array{role: ?string, seller: array{index: int, id: int, nip: string, name: string, city: string}, billing: array{overdue: bool, invoices: list<array{days_ago: int, amount_cents: int}>}, complaints: list<array<string, mixed>>, sales: bool}>  $plans
     * @return int liczba sprzedawców z fakturami
     */
    private function persist(array $plans, SyntheticSellers $synthetic, BillingScenario $billing, QueueBackpressure $backpressure, Randomizer $random, DateTimeImmutable $now, float $started): int
    {
        // Nieopublikowane wiersze outboksu trafią zaraz do kolejki projektora — liczymy je razem z nią.
        $unpublished = fn (): int => DB::table('outbox')->whereNull('published_at')->count();
        $say = fn (string $line) => $this->line($line);

        $passwordHash = SyntheticSellers::unusablePasswordHash();
        // Akceptuje „moderator systemowy”: najstarszy admin, a bez admina 1 (to tylko liczba w zdarzeniu).
        $moderatorId = (int) (DB::table('users')->where('is_admin', true)->min('id') ?? 1);
        $withBilling = 0;

        foreach (array_chunk($plans, self::BATCH) as $batch) {
            $this->waited += $backpressure->waitBelow(self::PROJECTOR_QUEUE, QueueBackpressure::LIMIT, $unpublished, $say, $this->maxWait);
            DB::transaction(function () use ($batch, $synthetic, $billing, $random, $now, $passwordHash, $moderatorId, &$withBilling): void {
                foreach ($batch as $plan) {
                    $synthetic->persist($plan['seller'], $passwordHash, $moderatorId, $now);
                    if ($this->option('billing') || $plan['role'] !== null) {
                        $billing->apply($plan['seller']['id'], $plan['billing'], $random, $now);
                        $withBilling++;
                    }
                }
            });

            $done = end($batch)['seller']['index'] + 1;
            $this->line(sprintf('  %d/%d sprzedawców (%.1f s)', $done, count($plans), microtime(true) - $started));
        }

        return $withBilling;
    }

    /**
     * @param  list<array{role: ?string, seller: array{id: int}, sales: bool}>  $plans
     * @return array{int, int} [sprzedawcy, zdarzenia]
     */
    private function publishSales(array $plans, SimulatedEventPublisher $publisher, QueueBackpressure $backpressure, Randomizer $random, DateTimeImmutable $now): array
    {
        $targets = [];
        if ($this->option('sales')) {
            $real = DB::table('sellers')->where('synthetic', false)->where('status', 'active')->orderBy('id')->pluck('id');
            foreach ($real as $id) {
                $targets[] = [(int) $id, SalesEvents::PROFILE_STABLE];
            }
        }
        foreach ($plans as $plan) {
            if ($plan['role'] === null && ! ($this->option('sales') && $plan['sales'])) {
                continue;
            }
            $targets[] = [$plan['seller']['id'], match (true) {
                $plan['role'] === null => SalesEvents::PROFILE_STABLE,
                PlantedSellers::satisfies($plan['role'], 'drop') => SalesEvents::PROFILE_PLANTED,
                default => SalesEvents::PROFILE_STABLE_40,
            }];
        }

        $events = 0;
        $checkedAt = null;
        $say = fn (string $line) => $this->line($line);
        foreach ($targets as [$sellerId, $profile]) {
            if ($checkedAt === null || $events - $checkedAt >= self::SALES_CHECK_EVERY) {
                $this->waited += $backpressure->waitBelow(self::ANALYTICS_QUEUE, QueueBackpressure::LIMIT, onWait: $say, maxWaitSeconds: $this->maxWait);
                $checkedAt = $events;
            }
            $events += SalesEvents::publish($publisher, $random, $sellerId, SalesEvents::draw($random, $profile), $now);
        }
        $publisher->finish();

        return [count($targets), $events];
    }

    /**
     * Hamulec nie może czekać dalej: część paczek mogła już trafić do bazy (i do outboksu), więc
     * ponowienie wymaga czystego stanu.
     */
    private function abort(QueueBackpressureFailed $e, QueueBackpressure $backpressure): int
    {
        $backpressure->close();
        $this->error($e->getMessage());
        $this->line('Generator przerwany; zapisane paczki zostały w bazie. Przed ponowieniem:');
        $this->line('  php artisan seller360:simulate --purge');
        $this->line('  '.self::SEARCH_PURGE_COMMAND.'  (po opróżnieniu kolejki projektora)');

        return self::FAILURE;
    }

    private function purge(string $dir): int
    {
        $counts = SyntheticSellers::purge();
        @unlink($dir.'/planted.json');

        $this->info(sprintf(
            'Usunięto: sprzedawców %d, użytkowników %d, faktur %d, ofert %d.',
            $counts['sellers'], $counts['users'], $counts['invoices'], $counts['offers'],
        ));
        $this->line('Catalog nie pisze do Elasticsearcha. Usuń dokumenty syntetyczne w search (po opróżnieniu kolejki projektora):');
        $this->line('  '.self::SEARCH_PURGE_COMMAND);

        return self::SUCCESS;
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }
}
