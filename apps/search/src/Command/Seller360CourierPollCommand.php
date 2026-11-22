<?php

namespace App\Command;

use App\Seller360\Registry\CourierFanOut;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Jednorazowy fan-out ocen kuriera (to samo robi `seller360:scheduler` co 60 s); `since` z Redisa `seller360:courier:since`. */
#[AsCommand(name: 'seller360:courier:poll', description: 'Rozsyła zmienione oceny kurierów do dokumentów seller-360 (_update_by_query)')]
final class Seller360CourierPollCommand extends Command
{
    public function __construct(private readonly CourierFanOut $fanOut)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('once', null, InputOption::VALUE_NONE, 'Jeden przebieg (jedyny tryb; opcja dla zgodności z przewodnikiem)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $r = $this->fanOut->pollFromRedis();
        $output->writeln(\sprintf('couriers=%d updated=%d noops=%d conflicts=%d failures=%d took_ms=%d', $r->couriers, $r->updated, $r->noops, $r->conflicts, $r->failures, $r->tookMs));

        return Command::SUCCESS;
    }
}
