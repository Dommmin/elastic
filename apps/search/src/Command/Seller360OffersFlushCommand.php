<?php

namespace App\Command;

use App\Seller360\OffersSectionFlusher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Jednorazowe uruchomienie koalescencji ofert (to samo robi `seller360:scheduler` co 30 s). */
#[AsCommand(name: 'seller360:offers:flush', description: 'Zapisuje sekcję offers dla sprzedawców ze zbioru seller360:dirty:offers')]
final class Seller360OffersFlushCommand extends Command
{
    public function __construct(private readonly OffersSectionFlusher $flusher)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(\sprintf('Zapisanych sekcji offers: %d', $this->flusher->flush()));

        return Command::SUCCESS;
    }
}
