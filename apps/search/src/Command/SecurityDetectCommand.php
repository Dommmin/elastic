<?php

namespace App\Command;

use App\Security\SecurityDetectJob;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `security:detect [--rule=ID] [--since=24h]` — uruchamia detektory (backfill reguł ES|QL i agregacji)
 * za ostatni okres i zapisuje alerty do `alerts-security`. Powtórka nie dubluje dokumentów (id z klucza alertu).
 */
#[AsCommand(name: 'security:detect', description: 'Uruchamia detektory nadużyć za ostatni okres i zapisuje alerty (idempotentnie)')]
final class SecurityDetectCommand extends Command
{
    public function __construct(private readonly SecurityDetectJob $job)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('rule', null, InputOption::VALUE_REQUIRED, 'Tylko ta reguła (rule.id)')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Zakres wstecz: <liczba>h albo <liczba>d', '24h');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $since = (string) $input->getOption('since');
        if (! preg_match('/^([1-9]\d*)(h|d)$/', $since, $m)) {
            $output->writeln('<error>--since: oczekiwano <liczba>h albo <liczba>d (np. 24h, 15d).</error>');

            return Command::INVALID;
        }

        $to = new \DateTimeImmutable();
        $from = $to->modify(\sprintf('-%d %s', $m[1], $m[2] === 'h' ? 'hours' : 'days'));
        $rule = $input->getOption('rule');

        try {
            $counts = $this->job->detect($from, $to, \is_string($rule) && $rule !== '' ? $rule : null);
        } catch (\Throwable $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return Command::FAILURE;
        }

        if ($counts === []) {
            $output->writeln('<error>Brak detektora'.(\is_string($rule) ? " dla reguły {$rule}" : '').'.</error>');

            return Command::FAILURE;
        }
        foreach ($counts as $id => $count) {
            $output->writeln(\sprintf('%s: %d alert(ów)', $id, $count));
        }

        return Command::SUCCESS;
    }
}
