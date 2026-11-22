<?php

namespace App\Command;

use App\Seller360\Seller360Scheduler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `seller360:scheduler` — jeden długo żyjący proces z zadaniami okresowymi seller-360
 * (offers 30 s, sales 300 s; kolejne zadania dochodzą przez tag `app.seller360_job`).
 * SIGTERM i SIGINT kończą pętlę po bieżącym zadaniu.
 */
#[AsCommand(name: 'seller360:scheduler', description: 'Pętla zadań okresowych seller-360 (offers, sales)')]
final class Seller360SchedulerCommand extends Command
{
    use CancelsOnSigint;

    public function __construct(private readonly Seller360Scheduler $scheduler)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->withSigint(function () use ($output): void {
            $previous = pcntl_signal_get_handler(\SIGTERM);
            pcntl_signal(\SIGTERM, function (): void {
                $this->interrupted = true;
            });
            try {
                $output->writeln('seller360:scheduler: start');
                $this->scheduler->run(fn (): bool => $this->interrupted);
                $output->writeln('seller360:scheduler: koniec po sygnale');
            } finally {
                pcntl_signal(\SIGTERM, $previous);
            }
        });

        return Command::SUCCESS;
    }
}
