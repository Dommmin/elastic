<?php

namespace App\Command;

use App\Service\IndexAliases;
use Elastic\Elasticsearch\Client;
use App\Service\SellerRenamer;
use App\Service\TaskWaiter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `search:seller:rename <seller_id> <nowa_nazwa>` — druga połowa zmiany nazwy
 * sprzedawcy (pierwsza: `marketplace:seller-rename` w catalogu, tylko baza).
 * Poprawia `offers[].seller` w dokumentach przez `_update_by_query` na
 * `products-write` jako zadanie w tle: `make es-tasks` pokazuje postęp,
 * `make es-rethrottle` zmienia tempo, `make es-cancel` zatrzymuje.
 * Wznowienie = to samo polecenie jeszcze raz (zapytanie pomija poprawione).
 *
 * Odmawia w trakcie migracji (alias odczytu ≠ alias zapisu, czyli między
 * switch-write a switch-read): zmiana trafiłaby tylko do vN+1, a nadrabianie
 * po `make reindex-abort` bierze produkty z outboxu — zmiana nazwy sprzedawcy
 * outboxu nie zapisuje, więc vN zostałby ze starą nazwą (Task 12b, przegląd).
 * `--check-only` sprawdza tylko tę bramkę — `make seller-rename` woła ją
 * PRZED zmianą w bazie catalogu, żeby nie zostawić bazy i ES w rozjeździe.
 *
 * Linia dla skryptów: `TASK=<id>`. Kody: 0 sukces, 1 błąd, 130 przerwane Ctrl-C.
 */
#[AsCommand(name: 'search:seller:rename', description: 'Zmienia nazwę sprzedawcy w dokumentach ES (_update_by_query, zadanie w tle)')]
final class SellerRenameIndexCommand extends Command
{
    use CancelsOnSigint;

    private const EXIT_INTERRUPTED = 130;

    public function __construct(
        private readonly SellerRenamer $renamer,
        private readonly TaskWaiter $waiter,
        private readonly Client $client,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('seller_id', InputArgument::REQUIRED, 'ID sprzedawcy (offers.seller_id)')
            ->addArgument('name', InputArgument::REQUIRED, 'Nowa nazwa')
            ->addOption('rps', null, InputOption::VALUE_REQUIRED, 'requests_per_second (-1 = bez limitu)', '500')
            ->addOption('no-wait', null, InputOption::VALUE_NONE, 'Wypisz TASK= i zakończ, bez śledzenia')
            ->addOption('index', null, InputOption::VALUE_REQUIRED, 'Indeks albo alias do zmiany (testy)', IndexAliases::WRITE)
            ->addOption('check-only', null, InputOption::VALUE_NONE, 'Tylko sprawdź, czy nie trwa migracja indeksu (przed zmianą w bazie)')
            ->addOption('read-alias', null, InputOption::VALUE_REQUIRED, 'Alias odczytu (tylko testy)', IndexAliases::READ)
            ->addOption('write-alias', null, InputOption::VALUE_REQUIRED, 'Alias zapisu (tylko testy)', IndexAliases::WRITE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sellerId = (string) $input->getArgument('seller_id');
        $name = trim((string) $input->getArgument('name'));
        $rps = $input->getOption('rps');

        if (! ctype_digit($sellerId) || $name === '' || ! is_numeric($rps)) {
            $io->error('seller_id to liczba, nazwa nie może być pusta, --rps to liczba (-1 = bez limitu).');

            return Command::FAILURE;
        }

        $aliases = new IndexAliases($this->client, (string) $input->getOption('read-alias'), (string) $input->getOption('write-alias'));
        $read = $aliases->readTarget();
        $write = $aliases->writeTarget();
        if ($read !== null && $write !== null && $read !== $write) {
            $io->error("Odmawiam: trwa migracja indeksu ({$aliases->read} → {$read}, {$aliases->write} → {$write}) — dokończ make reindex albo make reindex-abort. "
                .'Zmiana nazwy trafiłaby tylko do nowego indeksu, a nadrabianie po abort jej nie zobaczy (nie ma jej w outboxie).');

            return Command::FAILURE;
        }
        if ($input->getOption('check-only')) {
            $io->success('Brak migracji w toku — można zmieniać nazwę.');

            return Command::SUCCESS;
        }

        $taskId = $this->renamer->start((string) $input->getOption('index'), $sellerId, $name, (float) $rps);
        $output->writeln("TASK={$taskId}");

        if ($input->getOption('no-wait')) {
            return Command::SUCCESS;
        }

        $outcome = $this->withSigint(fn () => $this->waiter->wait(
            $taskId,
            static fn (array $status) => $output->writeln('postęp: '.self::counts($status)),
            fn (): bool => $this->interrupted,
        ));

        $output->writeln(($outcome['cancelled'] ? 'anulowano: ' : 'zakończono: ').self::counts($outcome['result']));

        if ($outcome['cancelled']) {
            $io->warning('_update_by_query anulowany. Wznowienie: to samo polecenie jeszcze raz (pomija dokumenty już poprawione).');

            return self::EXIT_INTERRUPTED;
        }

        $failures = $outcome['result']['failures'] ?? [];
        if ($outcome['error'] !== null || $failures !== []) {
            $io->error('_update_by_query z błędami: '.json_encode($outcome['error'] ?? \array_slice($failures, 0, 3), \JSON_UNESCAPED_UNICODE));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /** @param array<string, mixed> $status */
    private static function counts(array $status): string
    {
        return sprintf(
            'updated=%d version_conflicts=%d total=%d',
            $status['updated'] ?? 0,
            $status['version_conflicts'] ?? 0,
            $status['total'] ?? 0,
        );
    }
}
