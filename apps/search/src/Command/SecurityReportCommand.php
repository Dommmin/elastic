<?php

namespace App\Command;

use App\Security\DetectionScore;
use Elastic\Elasticsearch\Client;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `security:report --truth=PATH [--json]` — skuteczność reguł: alerty z `alerts-security` kontra prawda podstawowa
 * z `security:simulate` (`planted-security.json`). Tabela per reguła: TP, FP (w tym z bliskich pudeł), FN,
 * precision, recall; `--json` dla POMIARY.
 *
 * R7: oceniane są tylko alerty detektorów PHP (`_id` = sha1 klucza z ich pól); dokumenty konektora Kibany
 * liczymy osobno jako „Kibana live (unscored)”, a niesyntetyczne alerty spoza prawdy — jako prawdziwe dane.
 * Wszystkie alerty czytamy przez point-in-time z `search_after`, bez limitu 10 000.
 */
#[AsCommand(name: 'security:report', description: 'Precyzja i czułość reguł detekcji wobec prawdy podstawowej symulatora')]
final class SecurityReportCommand extends Command
{
    private const PAGE = 1000;

    public function __construct(
        private readonly Client $client,
        private readonly string $alertsIndex = 'alerts-security',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('truth', null, InputOption::VALUE_REQUIRED, 'Ścieżka planted-security.json z security:simulate')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Wynik jako JSON (do POMIARY)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = (string) $input->getOption('truth');
        if ($path === '' || ! is_file($path)) {
            $output->writeln('<error>--truth: podaj istniejący plik planted-security.json.</error>');

            return Command::INVALID;
        }
        try {
            $truth = json_decode((string) file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $output->writeln("<error>{$path}: niepoprawny JSON ({$e->getMessage()}).</error>");

            return Command::INVALID;
        }
        $cases = $truth['cases'] ?? null;
        if (! \is_array($cases)) {
            $output->writeln("<error>{$path}: brak listy `cases`.</error>");

            return Command::INVALID;
        }

        $hits = $this->alerts();
        $parts = DetectionScore::partition($cases, $hits);
        $score = DetectionScore::score($cases, $parts['scored']);

        if ($input->getOption('json')) {
            $output->writeln(json_encode([
                'seed' => $truth['seed'] ?? null,
                'generated_at' => $truth['generated_at'] ?? null,
                'p95' => $truth['p95'] ?? null,
                'alerts_total' => \count($hits),
                'alerts_scored' => \count($parts['scored']),
                'kibana_live_unscored' => $parts['kibana'],
                'real_unscored' => $parts['unrelated'],
                'rules' => $score,
            ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $output->writeln(\sprintf('Prawda: %s (ziarno %s, %s, p95 %s), przypadków: %d', $path, $truth['seed'] ?? '?', $truth['generated_at'] ?? '?', $truth['p95'] ?? '?', \count($cases)));
        $table = new Table($output);
        $table->setHeaders(['reguła', 'TP', 'FP', 'FP z bliskich pudeł', 'FN', 'precision', 'recall']);
        $format = static fn (?float $v): string => $v === null ? '—' : number_format($v, 2);
        foreach ($score as $rule => $s) {
            $table->addRow([$rule, $s['tp'], $s['fp'], $s['fp_near_miss'], $s['fn'], $format($s['precision']), $format($s['recall'])]);
        }
        $table->render();
        $output->writeln(\sprintf('Alertów: %d, ocenionych: %d, Kibana live (unscored): %d, prawdziwe spoza symulacji (unscored): %d', \count($hits), \count($parts['scored']), $parts['kibana'], $parts['unrelated']));

        return Command::SUCCESS;
    }

    /** @return list<array{_id: string, _source: array<string, mixed>}> */
    private function alerts(): array
    {
        $this->client->indices()->refresh(['index' => $this->alertsIndex]);
        $pit = $this->client->openPointInTime(['index' => $this->alertsIndex, 'keep_alive' => '1m'])->asArray()['id'];
        $hits = [];
        $after = null;
        try {
            do {
                $body = ['size' => self::PAGE, 'pit' => ['id' => $pit, 'keep_alive' => '1m'], 'sort' => [['_shard_doc' => 'asc']]];
                if ($after !== null) {
                    $body['search_after'] = $after;
                }
                $response = $this->client->search(['body' => $body])->asArray();
                $pit = $response['pit_id'] ?? $pit;
                $page = $response['hits']['hits'] ?? [];
                foreach ($page as $hit) {
                    $hits[] = ['_id' => (string) $hit['_id'], '_source' => $hit['_source'] ?? []];
                }
                $after = $page !== [] ? ($page[array_key_last($page)]['sort'] ?? null) : null;
            } while (\count($page) === self::PAGE && $after !== null);
        } finally {
            $this->client->closePointInTime(['body' => ['id' => $pit]]);
        }

        return $hits;
    }
}
