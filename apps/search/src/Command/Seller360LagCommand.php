<?php

namespace App\Command;

use App\Seller360\LagReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Jednorazowy raport świeżości sekcji seller-360 (to samo robi `seller360:scheduler` co 30 s); zapisuje też wynik w Redisie. */
#[AsCommand(name: 'seller360:lag', description: 'Lag per sekcja seller-360 (p50, p99, max, wiek ostatniego zapisu)')]
final class Seller360LagCommand extends Command
{
    public function __construct(private readonly LagReport $report)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table = new Table($output);
        $table->setHeaders(['sekcja', 'docs', 'p50 [s]', 'p99 [s]', 'max [s]', 'age_max [s]']);
        foreach ($this->report->refresh(new \DateTimeImmutable) as $section => $r) {
            $table->addRow([$section, $r['docs'], round($r['p50'], 1), round($r['p99'], 1), round($r['max'], 1), round($r['age_max'])]);
        }
        $table->render();

        return Command::SUCCESS;
    }
}
