<?php

namespace App\Analytics\Simulation;

use Illuminate\Support\Facades\DB;
use Random\Randomizer;

/**
 * Pula fraz symulatora z wagami Zipfa (s = 1.1) plus ~5% fraz spoza katalogu.
 *
 * Frazy z katalogu: nazwy kategorii, nazwy marek, nazwy produktów bez prefiksu
 * marki („słuchawki nauszne alpha” — duże grupy remisu, po ~900 produktów)
 * i najczęstsze słowa tych nazw. Wszystko z agregatów SQL (`GROUP BY`) — przy
 * ~300k produktów do PHP trafia kilka tysięcy wierszy, nie cały katalog.
 * SQL jest przenośny (Postgres i SQLite z testów); małe litery i podział na
 * słowa robi PHP, bo `lower()` w SQLite nie zna polskich znaków.
 *
 * Kolejność popularności (ranga w Zipfie) jest losowa, ale stała dla ziarna:
 * hash (ziarno, fraza). Dzięki temu ranga nie zależy od rozmiaru katalogu ani
 * kolejności wierszy z bazy.
 */
final class QueryPool
{
    public const ZIPF_S = 1.1;

    public const OUT_OF_CATALOG_SHARE = 0.05;

    /** Ile najczęstszych słów nazw trafia do puli. */
    public const TOP_WORDS = 40;

    /**
     * Frazy, dla których katalog nie ma produktów: towary spoza oferty i literówki
     * (sprawdzone na żywym indeksie: total_hits = 0; uwaga na słowa wspólne
     * z nazwami, np. „do” z „buty do biegania” — dają trafienia). Znormalizowane.
     */
    public const OUT_OF_CATALOG = [
        'lodówka side by side',
        'pralka',
        'telewizor oled',
        'ekspres ciśnieniowy',
        'odkurzacz pionowy',
        'konsola playstation',
        'drukarka atramentowa',
        'zmywarka',
        'suszarka bębnowa',
        'laptpo',
        'słuchwki',
        'mikrofalówka',
        'karma dla kota',
    ];

    /** @var list<string> */
    private readonly array $phrases;

    /** @var list<float> skumulowane, znormalizowane wagi Zipfa */
    private readonly array $cumulative;

    /**
     * @param  list<string>  $phrases  w kolejności rangi (pierwsza = najpopularniejsza)
     */
    public function __construct(array $phrases)
    {
        if ($phrases === []) {
            throw new \InvalidArgumentException('Pula fraz jest pusta — katalog bez produktów?');
        }

        $this->phrases = array_values($phrases);

        $weights = [];
        foreach ($this->phrases as $rank => $_) {
            $weights[] = 1 / (($rank + 1) ** self::ZIPF_S);
        }
        $total = array_sum($weights);

        $cumulative = [];
        $running = 0.0;
        foreach ($weights as $w) {
            $running += $w / $total;
            $cumulative[] = $running;
        }
        $this->cumulative = $cumulative;
    }

    public static function fromCatalog(int $seed): self
    {
        /** @var array<string, string> $byQuery znormalizowana fraza → surowa */
        $byQuery = [];
        $add = static function (string $raw) use (&$byQuery): void {
            $raw = trim($raw);
            $query = mb_strtolower($raw);
            if ($query !== '' && ! isset($byQuery[$query]) && ! in_array($query, self::OUT_OF_CATALOG, true)) {
                $byQuery[$query] = $raw;
            }
        };

        foreach (DB::table('categories')->whereExists(fn ($q) => $q->from('products')->whereColumn('products.category_id', 'categories.id'))->orderBy('id')->pluck('name') as $name) {
            $add($name);
        }
        foreach (DB::table('brands')->whereExists(fn ($q) => $q->from('products')->whereColumn('products.brand_id', 'brands.id'))->orderBy('id')->pluck('name') as $name) {
            $add($name);
        }

        $rows = DB::table('products')
            ->join('brands', 'brands.id', '=', 'products.brand_id')
            ->groupBy('products.name', 'brands.name')
            ->orderBy('products.name')
            ->select('products.name as name', 'brands.name as brand', DB::raw('count(*) as c'))
            ->get();

        /** @var array<string, int> $models */
        $models = [];
        foreach ($rows as $row) {
            $name = (string) $row->name;
            $brand = (string) $row->brand;
            $model = str_starts_with($name, $brand.' ') ? substr($name, strlen($brand) + 1) : $name;
            $model = mb_strtolower(trim($model));
            $models[$model] = ($models[$model] ?? 0) + (int) $row->c;
        }

        /** @var array<string, int> $words */
        $words = [];
        foreach ($models as $model => $count) {
            $add($model);
            foreach (preg_split('/\s+/u', $model) ?: [] as $word) {
                if (preg_match('/^\p{L}{3,}$/u', $word) === 1) {
                    $words[$word] = ($words[$word] ?? 0) + $count;
                }
            }
        }

        uksort($words, static fn (string $a, string $b): int => [$words[$b], $a] <=> [$words[$a], $b]);
        foreach (array_slice(array_keys($words), 0, self::TOP_WORDS) as $word) {
            $add($word);
        }

        $raws = array_values($byQuery);
        usort($raws, static fn (string $a, string $b): int => hash('sha256', "rank|{$seed}|{$a}") <=> hash('sha256', "rank|{$seed}|{$b}"));

        return new self($raws);
    }

    /**
     * Surowa fraza (`query_raw`): z prawdopodobieństwem OUT_OF_CATALOG_SHARE spoza
     * katalogu, inaczej z puli wg Zipfa.
     */
    public function draw(Randomizer $random): string
    {
        if ($random->getFloat(0, 1) < self::OUT_OF_CATALOG_SHARE) {
            return $this->drawOutOfCatalog($random);
        }

        $u = $random->getFloat(0, 1);
        $low = 0;
        $high = count($this->cumulative) - 1;
        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            if ($this->cumulative[$mid] < $u) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }

        return $this->phrases[$low];
    }

    public function drawOutOfCatalog(Randomizer $random): string
    {
        return self::OUT_OF_CATALOG[$random->getInt(0, count(self::OUT_OF_CATALOG) - 1)];
    }

    /**
     * @return list<string>
     */
    public function phrases(): array
    {
        return $this->phrases;
    }
}
