<?php

namespace App\Command;

use App\Service\CatalogProjectionClient;
use App\Service\ElasticsearchIndexer;
use App\Service\IndexAliases;
use App\Service\IndexMigration;
use App\Service\IndexOutcome;
use App\Service\ProductSynchronizer;
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
 * `php bin/console search:index:backfill --index=<nazwa>` — droga B reindeksu
 * (ETAP 10, spec 5.6): zamiast `_reindex` z vN wypełnia wskazany indeks
 * prosto z catalogu (Postgres). Strony ID (`productIds`, kursor `id > after`)
 * → `ProductSynchronizer::sync()` każdego produktu, czyli dokładnie ta sama
 * decyzja i ten sam external versioning (`projected_at_us`), co consumer.
 * Nowszy dokument ze strumienia wygrywa (409 = `stale`), nie błąd.
 *
 * Cel: cel migracji (vN+1), alias zapisu (`--index=products-write`) albo
 * stary vN po reindex-abort (oba aliasy na nim, czyli też cel odczytu).
 * Zapis z external versioningiem do żywego indeksu jest tak bezpieczny jak
 * `search:reproject`: wygrywają tylko nowsze wersje. Pieczęć dostaje wyłącznie
 * cel migracji (`_meta.migration`).
 *
 * Pieczęć w `_meta.migration` (decyzja kontrolera D): przy starcie
 * kasowana, przy sukcesie z 0 błędów zapisywana (`fill_method: backfill`,
 * `backfill_completed_at`, liczniki) — dopiero z nią `finalize`
 * i `switch-read` uznają cel za wypełniony. Błąd = brak pieczęci, kod ≠ 0;
 * ponowne uruchomienie jest bezpieczne (te same zapisy).
 */
#[AsCommand(name: 'search:index:backfill', description: 'Wypełnia indeks z catalogu (droga B reindeksu): strony ID → sync każdego produktu')]
final class IndexBackfillCommand extends Command
{
    /** Ile błędów wypisać z identyfikatorem — reszta tylko w liczniku. */
    private const MAX_REPORTED_ERRORS = 20;

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
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Ile ID na stronę z catalogu', '1000')
            ->addOption('write-alias', null, InputOption::VALUE_REQUIRED, 'Alias zapisu (tylko testy)', IndexAliases::WRITE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = (string) $input->getOption('index');
        $batch = max(1, (int) $input->getOption('batch'));
        if ($name === '') {
            $io->error('Podaj --index=<indeks docelowy> (cel migracji albo products-write).');

            return Command::FAILURE;
        }

        $aliases = new IndexAliases($this->client, write: (string) $input->getOption('write-alias'));
        $index = $this->resolve($name, $aliases);
        if ($index === null) {
            $io->error("Nie ma indeksu ani aliasu zapisu {$name}.");

            return Command::FAILURE;
        }
        $isMigrationTarget = $this->migration->beginBackfill($index);
        $synchronizer = new ProductSynchronizer($this->catalogClient, new ElasticsearchIndexer($this->client, $index));
        $counts = ['indexed' => 0, 'stale' => 0, 'notfound' => 0, 'deleted' => 0, 'errors' => 0];

        $after = null;
        $page = 0;
        do {
            try {
                $ids = $this->catalogClient->productIds($after, $batch);
            } catch (ProjectionUnavailableException $e) {
                // Bez listy nie wiadomo, co pominięto — przerywamy (kod ≠ 0, bez pieczęci).
                ++$counts['errors'];
                $io->warning("Lista ID po {$after}: {$e->getMessage()}");

                break;
            }

            foreach ($ids['ids'] as $productId) {
                try {
                    $result = $synchronizer->sync((string) $productId);
                } catch (ProjectionUnavailableException|ClientResponseException|ServerResponseException $e) {
                    if (++$counts['errors'] <= self::MAX_REPORTED_ERRORS) {
                        $io->warning("Produkt {$productId}: {$e->getMessage()}");
                    }

                    continue;
                }

                ++$counts[match (true) {
                    $result->outcome === IndexOutcome::Stale => 'stale',
                    $result->outcome === IndexOutcome::NotFound => 'notfound',
                    $result->deleted => 'deleted',
                    default => 'indexed',
                }];
            }

            $output->writeln(sprintf('strona %d: %s', ++$page, self::summary($counts)));
            $after = $ids['next_after'];
        } while ($after !== null && $ids['ids'] !== []);

        if ($counts['errors'] > 0) {
            $io->error(self::summary($counts).' — bez pieczęci backfillu; uruchom ponownie.');

            return Command::FAILURE;
        }

        if ($isMigrationTarget) {
            $this->migration->completeBackfill($index, $counts);
        }
        $io->success(self::summary($counts).($isMigrationTarget ? " — {$index} oznaczony jako wypełniony." : " — {$index} nie jest celem migracji, bez pieczęci."));

        return Command::SUCCESS;
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
    private static function summary(array $counts): string
    {
        return sprintf(
            'indexed: %d, stale(409): %d, not_found: %d, deleted: %d, errors: %d',
            $counts['indexed'], $counts['stale'], $counts['notfound'], $counts['deleted'], $counts['errors'],
        );
    }
}
