<?php

namespace App\Command;

use App\Seller360\SyntheticPurger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Druga połowa `seller360:simulate --purge` (catalog czyści bazę, ta komenda indeksy seller-360).
 * Uruchamiać po opróżnieniu kolejki projektora, inaczej zaległe zdarzenia `seller.*` odtworzą dokumenty.
 */
#[AsCommand(name: 'seller360:purge-synthetic', description: 'Usuwa syntetycznych sprzedawców z seller-360 i seller-360-public')]
final class Seller360PurgeSyntheticCommand extends Command
{
    public function __construct(private readonly SyntheticPurger $purger)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->purger->purge() as $index => $deleted) {
            $output->writeln(\sprintf('%s: usunięto %d dokumentów syntetycznych', $index, $deleted));
        }

        return Command::SUCCESS;
    }
}
