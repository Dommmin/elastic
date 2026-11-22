<?php

namespace App\Command;

use App\Seller360\Rebuild\BillingParity;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Sekcje `billing` w seller-360 kontra `/api/internal/billing/accounts` (wszystkie konta). Kod wyjścia 1 przy rozjeździe. */
#[AsCommand(name: 'seller360:billing:parity', description: 'Porównuje sekcje billing w seller-360 ze snapshotami kont z catalogu')]
final class Seller360BillingParityCommand extends Command
{
    public function __construct(private readonly BillingParity $parity)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->parity->check();
        $output->writeln(\sprintf('accounts=%d mismatches=%d', $result['accounts'], \count($result['mismatches'])));
        foreach (\array_slice($result['mismatches'], 0, 20) as $line) {
            $output->writeln('  rozjazd '.$line);
        }

        return $result['mismatches'] === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
