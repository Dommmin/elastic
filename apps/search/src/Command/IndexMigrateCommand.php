<?php

namespace App\Command;

use App\Service\IndexAliases;
use App\Service\IndexGate;
use App\Service\IndexMigration;
use App\Service\MigrationRefused;
use Elastic\Elasticsearch\Client;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `php bin/console search:index:migrate <krok>` — jeden krok migracji
 * indeksu produktów bez przestoju (ETAP 10, spec 5.3). Orkiestruje je
 * `tools/es/reindex.sh`; logika i warunki wstępne: IndexMigration.
 *
 * Linie dla skryptów (dokładnie w tej postaci): `TARGET=<indeks>`,
 * `TASK=<id>`, `GATE=PASS|FAIL`, `CATCHUP_SINCE=<ISO>` (abort: `switch_write_at`,
 * początek okna nadrabiania przed odjęciem marginesu).
 * Kody: 0 sukces, 1 odmowa/błąd, 130 reindeks przerwany Ctrl-C.
 */
#[AsCommand(name: 'search:index:migrate', description: 'Krok migracji indeksu: prepare|switch-write|reindex|finalize|switch-read|abort|cleanup')]
final class IndexMigrateCommand extends Command
{
    use CancelsOnSigint;

    private const STEPS = ['prepare', 'switch-write', 'reindex', 'finalize', 'switch-read', 'abort', 'cleanup'];
    private const EXIT_INTERRUPTED = 130;

    public function __construct(
        private readonly Client $client,
        private readonly IndexMigration $migration,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('step', InputArgument::REQUIRED, implode('|', self::STEPS))
            ->addOption('read-alias', null, InputOption::VALUE_REQUIRED, 'Alias odczytu (tylko testy)', IndexAliases::READ)
            ->addOption('write-alias', null, InputOption::VALUE_REQUIRED, 'Alias zapisu (tylko testy)', IndexAliases::WRITE)
            ->addOption('gc-deletes', null, InputOption::VALUE_REQUIRED, 'prepare: index.gc_deletes na czas migracji (dłużej niż cała migracja)', IndexMigration::DEFAULT_GC_DELETES)
            ->addOption('resume', null, InputOption::VALUE_NONE, 'prepare: przyjmij istniejący vN+1, jeśli utworzył go wcześniejszy prepare')
            ->addOption('slices', null, InputOption::VALUE_REQUIRED, 'reindex: liczba slice\'ów albo auto', 'auto')
            ->addOption('rps', null, InputOption::VALUE_REQUIRED, 'reindex: requests_per_second (-1 = bez limitu)', '-1')
            ->addOption('wait', null, InputOption::VALUE_NONE, 'reindex: śledź zadanie do końca (Ctrl-C anuluje)')
            ->addOption('tolerance', null, InputOption::VALUE_REQUIRED, 'switch-read: tolerancja bramki liczby dokumentów', (string) IndexGate::DEFAULT_TOLERANCE)
            ->addOption('yes', null, InputOption::VALUE_NONE, 'cleanup: naprawdę usuń (bez tego tylko lista)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $step = (string) $input->getArgument('step');
        $aliases = new IndexAliases($this->client, (string) $input->getOption('read-alias'), (string) $input->getOption('write-alias'));

        try {
            return match ($step) {
                'prepare' => $this->prepare($input, $output, $io, $aliases),
                'switch-write' => $this->switchWrite($io, $aliases),
                'reindex' => $this->reindex($input, $output, $io, $aliases),
                'finalize' => $this->finalize($io, $aliases),
                'switch-read' => $this->switchRead($input, $output, $io, $aliases),
                'abort' => $this->abort($output, $io, $aliases),
                'cleanup' => $this->cleanup($input, $io, $aliases),
                default => throw new MigrationRefused("Nieznany krok {$step}; dostępne: ".implode(', ', self::STEPS)),
            };
        } catch (MigrationRefused $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    private function prepare(InputInterface $input, OutputInterface $output, SymfonyStyle $io, IndexAliases $aliases): int
    {
        $target = $this->migration->prepare($aliases, (string) $input->getOption('gc-deletes'), (bool) $input->getOption('resume'));
        $io->success("{$target} gotowy: 0 replik, refresh_interval -1, gc_deletes {$input->getOption('gc-deletes')}.");
        $output->writeln("TARGET={$target}");

        return Command::SUCCESS;
    }

    private function switchWrite(SymfonyStyle $io, IndexAliases $aliases): int
    {
        $result = $this->migration->switchWrite($aliases);
        $result['already']
            ? $io->note("{$aliases->write} już wskazuje {$result['target']} — nic nie zmieniam.")
            : $io->success("{$aliases->write} → {$result['target']}. Odczyt nadal z poprzedniego indeksu.");

        return Command::SUCCESS;
    }

    private function reindex(InputInterface $input, OutputInterface $output, SymfonyStyle $io, IndexAliases $aliases): int
    {
        $slices = (string) $input->getOption('slices');
        $rps = $input->getOption('rps');
        if (! ($slices === 'auto' || ctype_digit($slices)) || ! is_numeric($rps)) {
            throw new MigrationRefused('--slices to auto albo liczba, --rps to liczba (-1 = bez limitu).');
        }

        $taskId = $this->migration->startReindex($aliases, $slices === 'auto' ? 'auto' : (int) $slices, (float) $rps);
        $output->writeln("TASK={$taskId}");

        if (! $input->getOption('wait')) {
            return Command::SUCCESS;
        }

        $outcome = $this->withSigint(fn () => $this->migration->waitForTask(
            $taskId,
            static fn (array $status) => $output->writeln('postęp: '.self::counts($status)),
            fn (): bool => $this->interrupted,
        ));

        $output->writeln(($outcome['cancelled'] ? 'anulowano: ' : 'zakończono: ').self::counts($outcome['result']));

        if ($outcome['cancelled']) {
            $io->warning("_reindex anulowany. Indeks zostaje — wznowienie: `reindex` od nowa (kopiuje tylko brakujące).");

            return self::EXIT_INTERRUPTED;
        }

        $failures = $outcome['result']['failures'] ?? [];
        if ($outcome['error'] !== null || $failures !== []) {
            $io->error('_reindex z błędami: '.json_encode($outcome['error'] ?? \array_slice($failures, 0, 3), \JSON_UNESCAPED_UNICODE));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function finalize(SymfonyStyle $io, IndexAliases $aliases): int
    {
        $target = $this->migration->finalize($aliases);
        $io->success("{$target}: repliki i refresh_interval z mapowania, odświeżony, zielony.");

        return Command::SUCCESS;
    }

    private function switchRead(InputInterface $input, OutputInterface $output, SymfonyStyle $io, IndexAliases $aliases): int
    {
        $tolerance = $input->getOption('tolerance');
        if (! is_numeric($tolerance) || (float) $tolerance < 0) {
            throw new MigrationRefused('--tolerance musi być nieujemną liczbą.');
        }

        $result = $this->migration->switchRead($aliases, (float) $tolerance);
        if ($result === null) {
            $io->success("{$aliases->read} był już przepięty — dokończono kroki po przepięciu (gc_deletes domyślne).");

            return Command::SUCCESS;
        }

        foreach ($result->lines() as $line) {
            $output->writeln($line);
        }

        if (! $result->passed()) {
            $io->error("Bramka nie przeszła — {$aliases->read} zostaje na poprzednim indeksie.");

            return Command::FAILURE;
        }

        $io->success("{$aliases->read} → {$result->index}, gc_deletes domyślne. Stary indeks zostaje do cleanup.");

        return Command::SUCCESS;
    }

    private function abort(OutputInterface $output, SymfonyStyle $io, IndexAliases $aliases): int
    {
        $result = $this->migration->abort($aliases);
        $io->warning("Oba aliasy z powrotem na {$result['source']}; {$result['target']} zostaje do diagnostyki. "
            ."{$result['source']} nie widział zapisów od switch-write ({$result['since']}). "
            .'make reindex-abort nadrabia je sam (krok 3/3); ręcznie: '
            ."search:index:backfill --index={$aliases->write} --since=<switch_write_at − margines, domyślnie 300 s>.");
        $output->writeln("CATCHUP_SINCE={$result['since']}");

        return Command::SUCCESS;
    }

    private function cleanup(InputInterface $input, SymfonyStyle $io, IndexAliases $aliases): int
    {
        ['delete' => $candidates, 'skipped' => $skipped] = $this->migration->cleanupCandidates($aliases);
        foreach ($skipped as $index) {
            $io->note("{$index}: przygotowany przez prepare, bez switch-write — pomijam (zaczęta migracja). Usuń ręcznie, jeśli porzucona.");
        }
        if ($candidates === []) {
            $io->note('Brak indeksów bez aliasu — nic do usunięcia.');

            return Command::SUCCESS;
        }

        if (! $input->getOption('yes')) {
            $io->listing($candidates);
            $io->note('Podgląd — nic nie usunięto. Dodaj --yes, żeby usunąć powyższe indeksy.');

            return Command::SUCCESS;
        }

        foreach ($candidates as $index) {
            $this->migration->deleteUnaliased($aliases, $index);
            $io->writeln("usunięto {$index}");
        }

        return Command::SUCCESS;
    }

    /** @param array<string, mixed> $status */
    private static function counts(array $status): string
    {
        return sprintf(
            'created=%d updated=%d deleted=%d version_conflicts=%d total=%d',
            $status['created'] ?? 0,
            $status['updated'] ?? 0,
            $status['deleted'] ?? 0,
            $status['version_conflicts'] ?? 0,
            $status['total'] ?? 0,
        );
    }
}
