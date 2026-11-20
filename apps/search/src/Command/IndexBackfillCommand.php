<?php

namespace App\Command;

use App\Service\CatalogProjectionClient;
use App\Service\ElasticsearchIndexer;
use App\Service\IndexAliases;
use App\Service\IndexMigration;
use App\Service\ProjectionUnavailableException;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `php bin/console search:index:backfill --index=<nazwa> [--batch=500] [--since=<ISO>]`
 * — droga B reindeksu (ETAP 10, spec 5.6): zamiast `_reindex` z vN wypełnia
 * wskazany indeks prosto z catalogu (Postgres).
 *
 * Partiami (Task 12b): strona ID → JEDNO żądanie o projekcje całej strony
 * (`fetchProjections`, ≤ 500) → JEDEN `_bulk` (`ElasticsearchIndexer::bulkSync`)
 * z external versioningiem (`projected_at_us` partii). Wcześniej każdy produkt
 * to było osobne żądanie projekcji i osobny zapis: ~37 dok./s, ~2 h 15 min na
 * 300 tys. Decyzja jest ta sama co w consumerze: nowszy dokument ze strumienia
 * wygrywa (409 = `stale`), produkt bez wiersza w catalogu jest usuwany
 * z wersją (404 = `not_found`, tombstone zostaje).
 *
 * Źródło ID:
 * - bez `--since`: wszystkie produkty (`/products/ids`, kursor `id > after`);
 * - z `--since`: tylko produkty zmienione od tej chwili według outboxu
 *   (`/products/changed`, także usunięte) — ograniczone nadrabianie po
 *   `reindex-abort`. To NIE jest pełne wypełnienie, więc `--since` nigdy nie
 *   dotyka pieczęci backfillu (ani jej nie kasuje, ani nie stawia).
 *
 * Cel: cel migracji (vN+1), alias zapisu (`--index=products-write`) albo
 * stary vN po reindex-abort (oba aliasy na nim, czyli też cel odczytu).
 * Zapis z external versioningiem do żywego indeksu jest tak bezpieczny jak
 * `search:reproject`: wygrywają tylko nowsze wersje. Pieczęć dostaje wyłącznie
 * cel migracji (`_meta.migration`).
 *
 * Pieczęć w `_meta.migration` (decyzja kontrolera D, pełny backfill): przy
 * starcie kasowana, przy sukcesie z 0 błędów zapisywana (`fill_method:
 * backfill`, `backfill_completed_at`, liczniki) — dopiero z nią `finalize`
 * i `switch-read` uznają cel za wypełniony. Błąd = brak pieczęci, kod ≠ 0;
 * ponowne uruchomienie jest bezpieczne (te same zapisy).
 */
#[AsCommand(name: 'search:index:backfill', description: 'Wypełnia indeks z catalogu (droga B reindeksu) albo nadrabia zmiany od --since: partie projekcji → _bulk')]
final class IndexBackfillCommand extends Command
{
    /** Ile błędów wypisać z identyfikatorem — reszta tylko w liczniku. */
    private const MAX_REPORTED_ERRORS = 20;

    /** Limit partii endpointu `/products/projections` w catalogu. */
    private const MAX_BATCH = 500;

    public function __construct(
        private readonly Client $client,
        private readonly CatalogProjectionClient $catalogClient,
        private readonly IndexMigration $migration,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('index', null, InputOption::VALUE_REQUIRED, 'Indeks docelowy (cel migracji, stary vN po abort) albo alias zapisu')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Ile produktów na partię (1..500): strona ID = jedno żądanie projekcji = jeden _bulk', (string) self::MAX_BATCH)
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Tylko produkty zmienione od tej chwili (ISO 8601, outbox catalogu) — nadrabianie, bez pieczęci')
            ->addOption('write-alias', null, InputOption::VALUE_REQUIRED, 'Alias zapisu (tylko testy)', IndexAliases::WRITE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = (string) $input->getOption('index');
        $batch = (int) $input->getOption('batch');
        if ($name === '') {
            $io->error('Podaj --index=<indeks docelowy> (cel migracji albo products-write).');

            return Command::FAILURE;
        }
        if ($batch < 1 || $batch > self::MAX_BATCH) {
            $io->error(\sprintf('--batch musi być w zakresie 1..%d (limit partii projekcji w catalogu).', self::MAX_BATCH));

            return Command::FAILURE;
        }
        $since = null;
        if ($input->getOption('since') !== null) {
            try {
                $since = (new \DateTimeImmutable((string) $input->getOption('since')))->format(\DATE_ATOM);
            } catch (\Exception) {
                $io->error("Nieprawidłowa data --since: {$input->getOption('since')} (oczekiwane ISO 8601).");

                return Command::FAILURE;
            }
        }

        $aliases = new IndexAliases($this->client, write: (string) $input->getOption('write-alias'));
        $index = $this->resolve($name, $aliases);
        if ($index === null) {
            $io->error("Nie ma indeksu ani aliasu zapisu {$name}.");

            return Command::FAILURE;
        }
        // --since to nadrabianie okna, nie pełne wypełnienie: pieczęci nie ruszamy wcale.
        $isMigrationTarget = $since === null && $this->migration->beginBackfill($index);
        if ($since !== null) {
            $output->writeln("nadrabianie {$index}: produkty zmienione od {$since} (outbox catalogu)");
        }
        $indexer = new ElasticsearchIndexer($this->client, $index);
        $counts = ['indexed' => 0, 'stale' => 0, 'notfound' => 0, 'deleted' => 0, 'errors' => 0];
        $start = microtime(true);

        $after = null;
        $page = 0;
        do {
            try {
                $ids = $since === null
                    ? $this->catalogClient->productIds($after, $batch)
                    : $this->catalogClient->changedProductIds($since, $after, $batch);
            } catch (ProjectionUnavailableException $e) {
                // Bez listy nie wiadomo, co pominięto — przerywamy (kod ≠ 0, bez pieczęci).
                ++$counts['errors'];
                $io->warning("Lista ID po {$after}: {$e->getMessage()}");

                break;
            }

            $this->syncPage($ids['ids'], $indexer, $counts, $io);

            $output->writeln(\sprintf('strona %d: %s', ++$page, self::summary($counts, $start)));
            $after = $ids['next_after'];
        } while ($after !== null && $ids['ids'] !== []);

        if ($counts['errors'] > 0) {
            $io->error(self::summary($counts, $start).($since === null ? ' — bez pieczęci backfillu; uruchom ponownie.' : ' — nadrabianie niepełne; uruchom ponownie.'));

            return Command::FAILURE;
        }

        if ($isMigrationTarget) {
            $this->migration->completeBackfill($index, $counts);
        }
        $io->success(self::summary($counts, $start).match (true) {
            $since !== null => " — nadrabianie od {$since}, bez pieczęci.",
            $isMigrationTarget => " — {$index} oznaczony jako wypełniony.",
            default => " — {$index} nie jest celem migracji, bez pieczęci.",
        });

        return Command::SUCCESS;
    }

    /**
     * Jedna strona: partia projekcji → `_bulk`. Błąd partii (catalog albo całe
     * żądanie do ES) liczy wszystkie jej ID jako błędy i idzie dalej — kod ≠ 0
     * na końcu, ponowne uruchomienie powtórzy te same zapisy.
     *
     * @param list<int>                                                                $ids
     * @param array{indexed: int, stale: int, notfound: int, deleted: int, errors: int} $counts
     */
    private function syncPage(array $ids, ElasticsearchIndexer $indexer, array &$counts, SymfonyStyle $io): void
    {
        if ($ids === []) {
            return;
        }

        try {
            $batch = $this->catalogClient->fetchProjections($ids);
            $result = $indexer->bulkSync($batch['items'], $batch['missing'], $batch['projected_at_us']);
        } catch (ProjectionUnavailableException|ClientResponseException|ServerResponseException $e) {
            $before = $counts['errors'];
            $counts['errors'] += \count($ids);
            if ($before < self::MAX_REPORTED_ERRORS) {
                $io->warning(\sprintf('Partia %d–%d (%d ID): %s', $ids[0], $ids[\count($ids) - 1], \count($ids), $e->getMessage()));
            }

            return;
        }

        $reported = $counts['errors'];
        $counts['indexed'] += $result['indexed'];
        $counts['stale'] += $result['stale'];
        $counts['notfound'] += $result['not_found'];
        $counts['deleted'] += $result['deleted'];
        $counts['errors'] += $result['errors'];
        foreach ($result['error_samples'] as $sample) {
            if (++$reported <= self::MAX_REPORTED_ERRORS) {
                $io->warning("Produkt {$sample}");
            }
        }
    }

    /** Alias zapisu → jego indeks; inaczej nazwa indeksu, o ile istnieje. */
    private function resolve(string $name, IndexAliases $aliases): ?string
    {
        if ($name === $aliases->write) {
            return $aliases->writeTarget();
        }

        return $this->client->indices()->exists(['index' => $name])->asBool() ? $name : null;
    }

    /** @param array{indexed: int, stale: int, notfound: int, deleted: int, errors: int} $counts */
    private static function summary(array $counts, float $start): string
    {
        $elapsed = max(0.001, microtime(true) - $start);
        $done = $counts['indexed'] + $counts['stale'] + $counts['notfound'] + $counts['deleted'];

        return \sprintf(
            'indexed: %d, stale(409): %d, not_found: %d, deleted: %d, errors: %d (%.0f s, %.0f dok./s)',
            $counts['indexed'], $counts['stale'], $counts['notfound'], $counts['deleted'], $counts['errors'], $elapsed, $done / $elapsed,
        );
    }
}
