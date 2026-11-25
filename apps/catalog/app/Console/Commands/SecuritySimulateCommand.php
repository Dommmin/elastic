<?php

namespace App\Console\Commands;

use App\Analytics\Simulation\SimulatedEventPublisher;
use App\Security\ClientFingerprint;
use App\Security\Simulation\PopularitySource;
use App\Security\Simulation\SecurityScenario;
use App\Seller360\Simulation\ComplaintsCsvWriter;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Symulator detekcji nadużyć (ETAP 15, Task 9): koperty `offer.price_changed`, `review.created` i
 * `user.clicked_result` z datą wsteczną prosto do `marketplace.events` (`SimulatedEventPublisher`), reklamacje
 * `SEC-*` w CSV dla `seller360:complaints:import` i prawda podstawowa w `planted-security.json`
 * (scenariusze i ich parametry: {@see SecurityScenario}).
 *
 * Wejścia z żywych danych, zawsze w stałej kolejności (po id): oferty produktów po obu stronach progu p95
 * (próg i popularność z ES kontem `catalog_app`), syntetyczni sprzedawcy ETAPU 13 bez zasadzonych i bliskich
 * pudeł z jego `planted.json` oraz bez sprzedawców z reklamacjami `SIM-` (CSV ETAPU 13) — dzięki temu
 * `seller360-verify` nie widzi zmian, a doba skoku ma wyłącznie reklamacje `SEC-` (flaga `labels.synthetic`).
 *
 * R1: bez użytkowników w bazie — id od 920 000 000 i `user_account_age_hours` wprost w zdarzeniu. Catalog nie
 * pisze do ES, więc sprzątanie to `security:purge-synthetic` w search.
 */
class SecuritySimulateCommand extends Command
{
    protected $signature = 'security:simulate
        {--seed=1 : Ziarno generatora}
        {--days=14 : Ile dni wstecz sięga historia (min. 10)}
        {--plant=5 : Zasadzone przypadki na regułę (plus tyle samo bliskich pudeł)}
        {--complaints=storage/app/security/complaints.csv : Ścieżka CSV z reklamacjami SEC- (względna wobec katalogu aplikacji)}
        {--seller360-complaints=storage/app/seller360/complaints.csv : CSV reklamacji ETAPU 13 — ich sprzedawcy są pomijani}';

    protected $description = 'Symuluje nadużycia sprzedawców i kupujących (5 reguł detekcji) z prawdą podstawową';

    public const TRUTH_FILE = 'planted-security.json';

    public const SEARCH_PURGE_COMMAND = 'docker compose exec search-consumer php bin/console security:purge-synthetic';

    private const OFFER_LIMIT = 5_000;

    private const MIN_PRICE_CENTS = 1_000;

    public function handle(SimulatedEventPublisher $publisher, PopularitySource $popularity, ClientFingerprint $fingerprint): int
    {
        $seed = (int) $this->option('seed');
        $days = (int) $this->option('days');
        $plant = (int) $this->option('plant');
        if ($days < SecurityScenario::MIN_DAYS || $days > 30 || $plant < 1 || $plant > 50) {
            $this->error('--days musi być w zakresie '.SecurityScenario::MIN_DAYS.'..30, --plant w zakresie 1..50.');

            return self::FAILURE;
        }
        if ($fingerprint->hash('192.0.2.1') === null) {
            $this->error('SECURITY_IP_PEPPER jest pusty: bez haszu IP reguła review-ip-burst nie ma czego liczyć.');

            return self::FAILURE;
        }

        $p95 = $popularity->p95();
        if ($p95 === null || $p95 <= 0) {
            $this->error('Brak p95 w security-rules/price-hug-threshold. Najpierw: make detection-setup');

            return self::FAILURE;
        }

        $high = $this->offers($popularity->productsAtLeast($p95 * SecurityScenario::HIGH_MARGIN, 1_000));
        $low = $this->offers($popularity->productsBelow($p95 * SecurityScenario::LOW_MARGIN, self::OFFER_LIMIT));
        $background = $this->offers(null);
        $sellers = $this->sellers();

        $now = (new DateTimeImmutable('@'.now()->getTimestamp()))->setTimezone(new DateTimeZone('UTC'));
        /** @var array{other: list<array{category: string, body: string}>} $templates */
        $templates = require resource_path('seller360/complaint-templates.php');

        try {
            $scenario = new SecurityScenario(new Randomizer(new Mt19937($seed)), $fingerprint, $now, $days, $plant, $templates['other']);
            $result = $scenario->generate($high, $low, $background, $sellers);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());
            $this->line(sprintf('Ofert ≥ p95: %d (potrzeba %d), < p95/2: %d (potrzeba %d), sprzedawców: %d (potrzeba %d).',
                count($high), SecurityScenario::highOffersNeeded($plant), count($low), SecurityScenario::lowOffersNeeded($plant),
                count($sellers), SecurityScenario::sellersNeeded($plant)));

            return self::FAILURE;
        }

        foreach ($result['envelopes'] as $envelope) {
            $publisher->publish($envelope);
        }
        $published = $publisher->finish();

        $csv = $this->absolute((string) $this->option('complaints'));
        $complaints = ComplaintsCsvWriter::write($csv, $seed, $result['complaints'], $now, SecurityScenario::TICKET_PREFIX);

        $truth = rtrim((string) config('services.security.simulation_dir'), '/').'/'.self::TRUTH_FILE;
        $this->writeTruth($truth, $seed, $now, $p95, $result['cases']);

        $types = array_count_values(array_column($result['envelopes'], 'type'));
        ksort($types);
        $this->table(['typ zdarzenia', 'liczba'], array_map(fn (string $type, int $count): array => [$type, $count], array_keys($types), $types));
        $this->line("Opublikowano {$published} zdarzeń, reklamacji SEC-: {$complaints}, p95 = {$p95}.");
        $this->line("CSV: {$csv}");
        $this->line("Prawda podstawowa: {$truth} (przypadków: ".count($result['cases']).')');
        $this->line('Sprzątanie (przed ponownym uruchomieniem): '.self::SEARCH_PURGE_COMMAND);

        return self::SUCCESS;
    }

    /**
     * @param  list<int>|null  $productIds  null = pula tła (dowolne produkty)
     * @return list<array{id: int, product_id: int, seller_id: int, price_cents: int}>
     */
    private function offers(?array $productIds): array
    {
        $query = DB::table('offers')->where('price_cents', '>=', self::MIN_PRICE_CENTS)->orderBy('id')->limit(self::OFFER_LIMIT);
        if ($productIds !== null) {
            if ($productIds === []) {
                return [];
            }
            $query->whereIntegerInRaw('product_id', $productIds);
        }

        return $query->get(['id', 'product_id', 'seller_id', 'price_cents'])
            ->map(fn (object $o): array => ['id' => (int) $o->id, 'product_id' => (int) $o->product_id, 'seller_id' => (int) $o->seller_id, 'price_cents' => (int) $o->price_cents])
            ->all();
    }

    /**
     * Syntetyczni sprzedawcy ETAPU 13 bez zasadzonych i bliskich pudeł z jego `planted.json`
     * oraz bez sprzedawców, którzy mają reklamacje `SIM-` w CSV ETAPU 13.
     *
     * @return list<array{id: int, nip: string}>
     */
    private function sellers(): array
    {
        $excludedIds = [];
        $planted = (string) config('seller360.simulation_dir').'/planted.json';
        if (is_file($planted)) {
            try {
                $json = json_decode((string) file_get_contents($planted), true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $json = [];
            }
            foreach ($json['planted'] ?? [] as $id) {
                $excludedIds[(int) $id] = true;
            }
            foreach ($json['near_misses'] ?? [] as $nearMiss) {
                $excludedIds[(int) ($nearMiss['id'] ?? 0)] = true;
            }
        }

        $excludedNips = [];
        $csv = $this->absolute((string) $this->option('seller360-complaints'));
        if (is_file($csv) && ($handle = fopen($csv, 'r')) !== false) {
            $header = fgetcsv($handle, escape: '') ?: [];
            $column = array_search('nip', $header, true);
            while ($column !== false && ($row = fgetcsv($handle, escape: '')) !== false) {
                $excludedNips[(string) ($row[$column] ?? '')] = true;
            }
            fclose($handle);
        }

        return DB::table('sellers')->where('synthetic', true)->whereNotNull('nip')->orderBy('id')->get(['id', 'nip'])
            ->reject(fn (object $s): bool => isset($excludedIds[(int) $s->id]) || isset($excludedNips[(string) $s->nip]))
            ->map(fn (object $s): array => ['id' => (int) $s->id, 'nip' => (string) $s->nip])
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, string>>  $cases
     */
    private function writeTruth(string $path, int $seed, DateTimeImmutable $now, float $p95, array $cases): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, json_encode([
            'seed' => $seed,
            'generated_at' => $now->format(DateTimeInterface::ATOM),
            'p95' => $p95,
            'cases' => $cases,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }
}
