<?php

namespace App\Command;

use App\Tracing\TraceContext;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * `php bin/console app:errors:smoke <marker>` — wywołuje błąd NA ŻYCZENIE, żeby
 * `make errors-smoke` sprawdziło drogę z aplikacji search do GlitchTipa
 * (ETAP 11b, ADR-0009). Tylko w środowisku dev.
 *
 * Ustawia TraceContext tak, jak robi to TraceContextSubscriber przy odbiorze
 * wiadomości, więc zdarzenie niesie tag `trace_id` (smoke porównuje go z Kibaną).
 * Sam ślad nie jest czyszczony: po wyjątku proces się kończy.
 */
#[AsCommand(name: 'app:errors:smoke', description: 'Wywołuje testowy błąd (tylko dev), żeby sprawdzić drogę do GlitchTipa')]
final class ErrorsSmokeCommand extends Command
{
    public function __construct(
        private readonly TraceContext $trace,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('marker', InputArgument::REQUIRED, 'Znacznik szukany potem w GlitchTipie');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->environment !== 'dev') {
            $output->writeln('<error>app:errors:smoke działa tylko w środowisku dev.</error>');

            return Command::FAILURE;
        }

        $marker = substr((string) preg_replace('/[^A-Za-z0-9._-]/', '', (string) $input->getArgument('marker')), 0, 64);
        $marker = $marker === '' ? 'none' : $marker;

        $this->trace->set(bin2hex(random_bytes(16)));

        throw new \RuntimeException("errors-smoke:search:{$marker}");
    }
}
