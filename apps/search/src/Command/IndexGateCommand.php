<?php

namespace App\Command;

use App\Service\IndexGate;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `php bin/console search:index:gate --index=products-v2 [--tolerance=0.001]`
 * — bramka jakości (ETAP 10, spec 5.5) na dowolnym indeksie fizycznym.
 * Ten sam kod woła `search:index:migrate switch-read`; ta komenda jest dla
 * skryptów (`make reindex-break`) i do ręcznego sprawdzenia. Kod 0 = PASS.
 */
#[AsCommand(name: 'search:index:gate', description: 'Bramka jakości indeksu: zielony i liczba dokumentów zgodna z catalogiem')]
final class IndexGateCommand extends Command
{
    public function __construct(private readonly IndexGate $gate)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('index', null, InputOption::VALUE_REQUIRED, 'Indeks fizyczny do sprawdzenia (np. products-v2)')
            ->addOption('tolerance', null, InputOption::VALUE_REQUIRED, 'Dopuszczalna względna różnica liczby dokumentów', (string) IndexGate::DEFAULT_TOLERANCE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $index = (string) $input->getOption('index');
        $tolerance = $input->getOption('tolerance');
        if ($index === '' || ! is_numeric($tolerance) || (float) $tolerance < 0) {
            $output->writeln('<error>Podaj --index i nieujemną --tolerance.</error>');

            return Command::INVALID;
        }

        $result = $this->gate->check($index, (float) $tolerance);
        foreach ($result->lines() as $line) {
            $output->writeln($line);
        }

        return $result->passed() ? Command::SUCCESS : Command::FAILURE;
    }
}
