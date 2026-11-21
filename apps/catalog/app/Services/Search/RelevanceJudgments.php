<?php

namespace App\Services\Search;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Zamienia REGUŁY oceny z `tests/relevance/queries.yaml` na listę
 * `id => grade` dla `_rank_eval` — prawdę bierze z bazy katalogu
 * (źródło prawdy), nie z ES (to, co oceniamy).
 *
 * Dlaczego reguły, a nie ręczne ID-ki (docs/RUNBOOK.md #096): zapytania
 * kategorii/synonimów dają dziesiątkom dokumentów IDENTYCZNY `_score`,
 * a remis rozstrzyga wewnętrzny doc id Lucene. Ręczna lista 10 z ~60
 * remisujących smartfonów = nDCG zależne od tego, które 10 akurat
 * wylosowało się na górę. Reguła `{ category: Smartfony }` ocenia CAŁĄ
 * grupę remisu tak samo, więc kolejność wewnątrz grupy przestaje mieć
 * znaczenie — wynik jest deterministyczny i nie zależy od liczby
 * produktów w seedzie.
 *
 * Selektory (dokładnie jeden na regułę): `id`, `name` (dokładna nazwa),
 * `name_contains` (fragment, bez rozróżniania wielkości liter), `brand`,
 * `category` (nazwa kategorii liścia), `ids` (mapa `id: ocena` — jawne oceny
 * pojedynczych produktów, bez `grade`; używa ich wygenerowany zestaw
 * `popularity-queries.yaml`, gdzie ocena każdego produktu z grupy remisu jest
 * inna). Dokument pasujący do kilku reguł dostaje NAJWYŻSZĄ z ocen.
 */
class RelevanceJudgments
{
    private const SELECTORS = ['id', 'name', 'name_contains', 'brand', 'category', 'ids'];

    private const MAX_GRADE = 3;

    /**
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<int|string, int> id produktu => grade
     */
    public function resolve(array $rules): array
    {
        $ratings = [];

        foreach ($rules as $rule) {
            $selector = $this->selector($rule);

            if ($selector === 'ids') {
                foreach ($this->explicitGrades($rule['ids']) as $id => $grade) {
                    $ratings[$id] = max($ratings[$id] ?? 0, $grade);
                }

                continue;
            }

            $grade = (int) ($rule['grade'] ?? throw new InvalidArgumentException('Reguła bez `grade`: '.json_encode($rule)));
            $ids = $this->matchingIds($selector, (string) $rule[$selector]);

            // Reguła, która nic nie łapie, to prawie na pewno literówka albo
            // plik nieaktualny względem seeda — cichy brak ocen zaniżyłby
            // nDCG bez śladu, więc głośno.
            if ($ids === []) {
                throw new InvalidArgumentException('Reguła nie pasuje do żadnego produktu: '.json_encode($rule, JSON_UNESCAPED_UNICODE));
            }

            foreach ($ids as $id) {
                $ratings[$id] = max($ratings[$id] ?? 0, $grade);
            }
        }

        return $ratings;
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function selector(array $rule): string
    {
        $selectors = array_values(array_intersect(self::SELECTORS, array_keys($rule)));

        if (count($selectors) !== 1) {
            throw new InvalidArgumentException('Reguła musi mieć dokładnie jeden selektor ('.implode('|', self::SELECTORS).'): '.json_encode($rule, JSON_UNESCAPED_UNICODE));
        }

        return $selectors[0];
    }

    /**
     * Jawne oceny `id: ocena`. Każdy produkt musi istnieć w katalogu — inaczej
     * plik jest nieaktualny względem seeda, a brakująca ocena po cichu
     * zaniżyłaby ideał nDCG.
     *
     * @return array<string, int>
     */
    private function explicitGrades(mixed $grades): array
    {
        if (! is_array($grades) || $grades === []) {
            throw new InvalidArgumentException('Selektor `ids` musi być niepustą mapą `id: ocena`.');
        }

        $normalized = [];
        foreach ($grades as $id => $grade) {
            if (! is_int($grade) || $grade < 0 || $grade > self::MAX_GRADE) {
                throw new InvalidArgumentException("Ocena produktu {$id} spoza skali 0–".self::MAX_GRADE.': '.json_encode($grade));
            }
            $normalized[(string) $id] = $grade;
        }

        $existing = Product::query()->whereKey(array_keys($normalized))->pluck('id')->map(fn ($id) => (string) $id)->all();
        $missing = array_diff(array_keys($normalized), $existing);

        if ($missing !== []) {
            throw new InvalidArgumentException('Produkty z `ids` nie istnieją w katalogu: '.implode(', ', array_slice($missing, 0, 20)));
        }

        return $normalized;
    }

    /**
     * @return array<int, string>
     */
    private function matchingIds(string $selector, string $value): array
    {
        $query = match ($selector) {
            'id' => Product::query()->whereKey($value),
            'name' => Product::query()->where('name', $value),
            'name_contains' => Product::query()->whereLike('name', '%'.$value.'%'),
            'brand' => Product::query()->whereHas('brand', fn (Builder $q) => $q->where('name', $value)),
            'category' => Product::query()->whereHas('category', fn (Builder $q) => $q->where('name', $value)),
        };

        return $query->orderBy('id')->pluck('id')->map(fn ($id) => (string) $id)->all();
    }
}
