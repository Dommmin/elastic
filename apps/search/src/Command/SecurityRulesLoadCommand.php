<?php

namespace App\Command;

use App\Security\RuleLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `security:rules:load` — wczytuje reguły percolatora z plików do `security-rules` i liczy progi p95
 * z `product-popularity` (ETAP 15). Wołane z `make detection-setup`; progi wypisuje, żeby było widać,
 * od jakiej popularności `price-hug-threshold` w ogóle strzela. `--dry-run` liczy i niczego nie zapisuje.
 */
#[AsCommand(name: 'security:rules:load', description: 'Ładuje reguły percolatora (security-rules) z plików, z progiem p95 popularności')]
final class SecurityRulesLoadCommand extends Command
{
    public function __construct(private readonly RuleLoader $loader)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Tylko policz progi, nie zapisuj reguł');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        try {
            $used = $this->loader->load($dryRun);
        } catch (\Throwable $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return Command::FAILURE;
        }

        foreach ($used as $id => $p95) {
            $output->writeln(\sprintf('%s: %s', $id, $p95 === null ? 'bez progu' : \sprintf('p95 popularności = %.6f', $p95)));
        }
        $output->writeln(\sprintf('%s %d reguł(y).', $dryRun ? 'Sprawdzono (dry-run):' : 'Załadowano', \count($used)));

        return Command::SUCCESS;
    }
}
