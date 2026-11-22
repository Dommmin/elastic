<?php

namespace App\Command;

use App\Seller360\SalesSectionRefresher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Jednorazowe przeliczenie sekcji sales (to samo robi `seller360:scheduler` co 300 s). */
#[AsCommand(name: 'seller360:sales:refresh', description: 'Przelicza sekcję sales z seller-sales-daily')]
final class Seller360SalesRefreshCommand extends Command
{
    public function __construct(private readonly SalesSectionRefresher $refresher)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(\sprintf('Zapisanych sekcji sales: %d', $this->refresher->refresh(new \DateTimeImmutable)));

        return Command::SUCCESS;
    }
}
