<?php

namespace App\Command;

use App\Seller360\Rebuild\Seller360Rebuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `seller360:rebuild` — przebudowa seller-360 i seller-360-public do `-v<N+1>` bez przestoju (ETAP 13, Task 14):
 * podwójny zapis, backfill z wersjami źródła, weryfikacja, atomowe przełączenie aliasów. Kroki: {@see Seller360Rebuilder}.
 * Uruchamiać w kontenerze z Redisem (`seller360-scheduler`). Kod wyjścia 1, gdy weryfikacja przerwała przebudowę.
 */
#[AsCommand(name: 'seller360:rebuild', description: 'Przebudowa seller-360 bez przestoju (podwójny zapis, backfill, weryfikacja, przełączenie aliasów)')]
final class Seller360RebuildCommand extends Command
{
    public function __construct(private readonly Seller360Rebuilder $rebuilder)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('delete-old', null, InputOption::VALUE_NONE, 'Usuń stare indeksy po przełączeniu');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->rebuilder->run((bool) $input->getOption('delete-old'), progress: static function (string $line) use ($output): void {
            $output->writeln($line);
        });

        $output->writeln(\sprintf('status=%s old=%s,%s new=%s,%s', $report->status, $report->oldMain, $report->oldPublic, $report->newMain, $report->newPublic));
        $output->writeln('phases_ms='.json_encode($report->phasesMs));
        $output->writeln('backfill='.json_encode($report->backfill));
        $output->writeln(\sprintf('counts_old=%s counts_new=%s sampled=%d mismatches=%d', json_encode($report->countsOld), json_encode($report->countsNew), $report->sampled, \count($report->mismatches)));
        foreach (\array_slice($report->mismatches, 0, 20) as $line) {
            $output->writeln('  rozjazd '.$line);
        }
        if ($report->status !== 'switched') {
            $output->writeln("<error>Przebudowa przerwana: aliasy bez zmian, podwójny zapis zatrzymany, {$report->newMain} i {$report->newPublic} zostają do oględzin.</error>");

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
