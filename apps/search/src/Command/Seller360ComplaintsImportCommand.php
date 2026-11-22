<?php

namespace App\Command;

use App\Seller360\Complaints\ComplaintsImporter;
use App\Seller360\Complaints\ImportResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Import reklamacji z CSV albo ponowienie wierszy bez dopasowania NIP (po dodaniu sprzedawcy).
 * Format pliku i działanie pipeline: {@see ComplaintsImporter}.
 */
#[AsCommand(name: 'seller360:complaints:import', description: 'Importuje reklamacje z CSV (NIP → sprzedawca przez enrich seller-by-nip)')]
final class Seller360ComplaintsImportCommand extends Command
{
    public function __construct(private readonly ComplaintsImporter $importer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'Plik CSV: '.implode(',', ComplaintsImporter::HEADER))
            ->addOption('retry-unmatched', null, InputOption::VALUE_NONE, 'Ponów wiersze z complaints-unmatched zamiast importować plik');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getArgument('path');
        $retry = (bool) $input->getOption('retry-unmatched');
        if (! $retry && ! \is_string($path)) {
            $output->writeln('<error>Podaj ścieżkę do pliku CSV albo użyj --retry-unmatched.</error>');

            return Command::INVALID;
        }

        try {
            $result = $retry ? $this->importer->retryUnmatched() : $this->importer->import((string) $path);
        } catch (\InvalidArgumentException $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");

            return Command::INVALID;
        }

        $output->writeln($this->summary($result, $retry));

        return $result->failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function summary(ImportResult $r, bool $retry): string
    {
        return \sprintf(
            '%s: indexed=%d unmatched=%d failed=%d, zapisanych sekcji complaints: %d',
            $retry ? 'Ponowienie' : 'Import',
            $r->indexed,
            $r->unmatched,
            $r->failed,
            $r->sections,
        );
    }
}
