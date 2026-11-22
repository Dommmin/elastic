<?php

namespace App\Console\Commands;

use App\Seller360\Seller360Query;
use App\Seller360\Seller360Reader;
use Illuminate\Console\Command;

/**
 * `php artisan seller360:query:verify` — dowód zapytania z DoD ETAPU 13 (spec §9).
 *
 * Prawda podstawowa to `planted.json` z `seller360:simulate --plant=N`: zasadzeni spełniają wszystkie
 * cztery warunki, bliskie pudła trzy z czterech. Zapytanie `Seller360Query::dodPreset()` musi zwrócić
 * dokładnie zasadzonych (precyzja = czułość = 1,0) i żadnego bliskiego pudła. Potem `--runs` powtórzeń
 * mierzy `took` z ES (p50/p99, liczby do POMIARY §17.1; cache ES jest wtedy ciepły, to czas w stanie ustalonym).
 */
class Seller360QueryVerifyCommand extends Command
{
    /** Z zapasem ponad liczbę zasadzonych: wynik musi się zmieścić w jednej stronie, żeby policzyć precyzję. */
    private const RESULT_SIZE = 200;

    protected $signature = 'seller360:query:verify {--runs=100 : Liczba powtórzeń do pomiaru took}';

    protected $description = 'Porównuje wynik zapytania z DoD seller-360 z planted.json i mierzy p50/p99 took';

    public function handle(Seller360Reader $reader): int
    {
        $path = config('seller360.simulation_dir').'/planted.json';
        $truth = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($truth) || ! is_array($truth['planted'] ?? null)) {
            $this->error("Brak prawdy podstawowej ({$path}). Najpierw: php artisan seller360:simulate --sellers=20000 --billing --sales --plant=5 …");

            return self::FAILURE;
        }

        $planted = array_map('intval', $truth['planted']);
        $nearMisses = [];
        foreach ($truth['near_misses'] ?? [] as $near) {
            $nearMisses[(int) $near['id']] = (string) $near['missing'];
        }

        $body = Seller360Query::fromFilters(Seller360Query::dodPreset(), size: self::RESULT_SIZE);
        $result = $reader->search($body);
        $found = array_map(static fn (array $hit): int => (int) $hit['id'], $result['hits']);

        $hits = array_values(array_intersect($found, $planted));
        $missed = array_values(array_diff($planted, $found));
        $extra = array_values(array_diff($found, $planted));
        $nearHit = array_values(array_intersect($extra, array_keys($nearMisses)));

        $precision = $found === [] ? 0.0 : count($hits) / count($found);
        $recall = $planted === [] ? 0.0 : count($hits) / count($planted);

        $this->line('Zapytanie z DoD: '.json_encode(Seller360Query::dodPreset(), JSON_UNESCAPED_UNICODE));
        $this->line(sprintf('Wynik: %d dokumentów (ES total %d): %s', count($found), $result['total'], implode(', ', $found)));
        $this->line(sprintf('Zasadzeni: %d, trafieni: %d', count($planted), count($hits)));
        $this->line(sprintf('Precyzja: %.3f', $precision));
        $this->line(sprintf('Czułość: %.3f', $recall));
        if ($missed !== []) {
            $this->line('Pominięte: '.implode(', ', $missed));
        }
        if ($extra !== []) {
            $this->line('Nadmiarowe: '.implode(', ', $extra));
        }
        $this->line('Bliskie pudła w wyniku: '.count($nearHit));
        foreach ($nearHit as $id) {
            $this->line(sprintf('  %d (brakuje: %s)', $id, $nearMisses[$id]));
        }

        $runs = max(1, (int) $this->option('runs'));
        $took = [];
        for ($i = 0; $i < $runs; $i++) {
            $took[] = $reader->search($body)['took'];
        }
        sort($took);
        $this->line(sprintf(
            'took z %d powtórzeń: p50 %d ms, p99 %d ms (min %d, max %d)',
            $runs,
            self::percentile($took, 0.50),
            self::percentile($took, 0.99),
            $took[0],
            $took[count($took) - 1],
        ));

        // Porównanie na liczbach, nie na floatach: 3/3 w PHP to int 1, a nie 1.0.
        $ok = $planted !== [] && $missed === [] && $extra === [] && $nearHit === [];
        $ok ? $this->info('DoD: OK') : $this->error('DoD: NIE SPEŁNIONE');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Percentyl metodą najbliższej rangi na posortowanej liście.
     *
     * @param  list<int>  $sorted
     */
    private static function percentile(array $sorted, float $q): int
    {
        $rank = (int) ceil($q * count($sorted));

        return $sorted[max(0, $rank - 1)];
    }
}
