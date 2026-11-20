<?php

namespace App\Command;

use App\Analytics\PopularityRefresher;
use App\Analytics\PopularityRefused;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `search:popularity:refresh` — przelicza popularność produktów z okna zdarzeń
 * i dokleja ją do `products-write` (kroki: `PopularityRefresher`). Uruchamiane
 * codziennie (`make popularity-refresh`); powtórka z tymi samymi opcjami daje
 * ten sam wynik, więc po błędzie albo Ctrl-C wystarczy uruchomić jeszcze raz.
 *
 * `--variant` wybiera, która wartość trafia do `popularity` (rank_feature);
 * obie zostają w `popularity_raw` / `popularity_ips` do porównań.
 * Kody: 0 sukces, 1 błąd albo odmowa (puste okno), 130 przerwane Ctrl-C.
 */
#[AsCommand(name: 'search:popularity:refresh', description: 'Przelicza popularność produktów (transform wsadowy, enrich, _update_by_query)')]
final class PopularityRefreshCommand extends Command
{
    use CancelsOnSigint;

    private const EXIT_INTERRUPTED = 130;

    public function __construct(private readonly PopularityRefresher $refresher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('variant', null, InputOption::VALUE_REQUIRED, 'Wartość w popularity: ips (korekta biasu pozycji) albo raw', 'ips')
            ->addOption('as-of', null, InputOption::VALUE_REQUIRED, 'Koniec okna (wyłącznie), YYYY-MM-DD albo ISO-8601; domyślnie teraz (UTC)')
            ->addOption('window-days', null, InputOption::VALUE_REQUIRED, 'Długość okna w dniach', '30')
            ->addOption('alpha', null, InputOption::VALUE_REQUIRED, 'Siła wygładzania α (> 0)', '20')
            ->addOption('rps', null, InputOption::VALUE_REQUIRED, 'requests_per_second dla _update_by_query (-1 = bez limitu)', '-1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $variant = (string) $input->getOption('variant');
        $days = (string) $input->getOption('window-days');
        $alpha = (string) $input->getOption('alpha');
        $rps = (string) $input->getOption('rps');

        if (! \in_array($variant, PopularityRefresher::VARIANTS, true) || ! ctype_digit($days) || (int) $days < 1
            || ! is_numeric($alpha) || (float) $alpha <= 0 || ! is_numeric($rps)) {
            $io->error('--variant=ips|raw, --window-days ≥ 1, --alpha > 0, --rps liczba (-1 = bez limitu).');

            return Command::FAILURE;
        }

        $asOf = self::parseAsOf($input->getOption('as-of'));
        if ($asOf === null) {
            $io->error('--as-of: YYYY-MM-DD albo ISO-8601 (np. 2026-11-20 lub 2026-11-20T06:00:00+01:00).');

            return Command::FAILURE;
        }

        try {
            $result = $this->withSigint(fn () => $this->refresher->refresh(
                $asOf, (int) $days, (float) $alpha, $variant, (float) $rps,
                onStep: static fn (string $step) => $output->writeln("→ {$step}"),
                onProgress: static fn (array $status) => $output->writeln('  postęp: '.self::counts($status)),
                shouldCancel: fn (): bool => $this->interrupted,
            ));
        } catch (PopularityRefused $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->section("Okno [{$result->from}, {$result->to}), wariant {$variant}, α={$alpha}");
        $rows = [];
        foreach ($result->propensity as $k => $p) {
            $c = $result->counts[$k];
            $rows[] = [$k, $c['impressions'], $c['clicks'], $c['impressions'] > 0 ? sprintf('%.4f', $c['clicks'] / $c['impressions']) : '—', sprintf('%.4f', $p)];
        }
        $io->table(['k', 'wyświetlenia', 'kliknięcia', 'CTR', 'p(k)'], $rows);

        $io->definitionList(
            ['c̄ (prior surowy: kliknięcia 1..20 / wyświetlenia)' => sprintf('%.5f', $result->meanCtr)],
            ['c̄_ips (prior IPS: Σ 1/p(k) / wyświetlenia)' => sprintf('%.5f', $result->meanCtrIps)],
            ['produktów w product-popularity' => $result->products],
            ['UBQ' => self::counts($result->ubq)],
            ['czasy' => implode(', ', array_map(static fn (string $step, float $s): string => sprintf('%s %.1f s', $step, $s), array_keys($result->timings), $result->timings))],
        );

        if ($result->cancelled) {
            $io->warning('_update_by_query anulowany — popularność w product-popularity jest nowa, w części produktów stara. Uruchom ponownie.');

            return self::EXIT_INTERRUPTED;
        }

        $failures = $result->ubq['failures'] ?? [];
        if ($result->ubqError !== null || $failures !== []) {
            $io->error('_update_by_query z błędami: '.json_encode($result->ubqError ?? \array_slice($failures, 0, 3), \JSON_UNESCAPED_UNICODE));

            return Command::FAILURE;
        }

        // conflicts: proceed — produkt zapisany w trakcie przez strumień przeszedł
        // przez ten sam pipeline z nową kopią enrich, więc i tak ma aktualną popularność.
        $conflicts = (int) ($result->ubq['version_conflicts'] ?? 0);
        if ($conflicts > 0) {
            $io->note("{$conflicts} konfliktów wersji — te produkty zapisał w międzyczasie strumień (przez ten sam pipeline, z nową popularnością).");
        }

        $io->success('Popularność przeliczona.');

        return Command::SUCCESS;
    }

    private static function parseAsOf(mixed $value): ?\DateTimeImmutable
    {
        $utc = new \DateTimeZone('UTC');
        if ($value === null || $value === '') {
            return new \DateTimeImmutable('now', $utc);
        }
        $value = (string) $value;

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $utc);

            return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
        }

        try {
            return (new \DateTimeImmutable($value, $utc))->setTimezone($utc);
        } catch (\Exception) {
            return null;
        }
    }

    /** @param array<string, mixed> $status */
    private static function counts(array $status): string
    {
        return sprintf(
            'updated=%d version_conflicts=%d total=%d',
            $status['updated'] ?? 0,
            $status['version_conflicts'] ?? 0,
            $status['total'] ?? 0,
        );
    }
}
