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
 * `category` (nazwa kategorii liścia). Dokument pasujący do kilku reguł
 * dostaje NAJWYŻSZĄ z ocen.
 */
class RelevanceJudgments
{
    private const SELECTORS = ['id', 'name', 'name_contains', 'brand', 'category'];

    /**
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<int|string, int> id produktu => grade
     */
    public function resolve(array $rules): array
    {
        $ratings = [];

        foreach ($rules as $rule) {
            $grade = (int) ($rule['grade'] ?? throw new InvalidArgumentException('Reguła bez `grade`: '.json_encode($rule)));
            $ids = $this->matchingIds($rule);

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
     * @return array<int, string>
     */
    private function matchingIds(array $rule): array
    {
        $selectors = array_values(array_intersect(self::SELECTORS, array_keys($rule)));

        if (count($selectors) !== 1) {
            throw new InvalidArgumentException('Reguła musi mieć dokładnie jeden selektor ('.implode('|', self::SELECTORS).'): '.json_encode($rule, JSON_UNESCAPED_UNICODE));
        }

        $value = (string) $rule[$selectors[0]];

        $query = match ($selectors[0]) {
            'id' => Product::query()->whereKey($value),
            'name' => Product::query()->where('name', $value),
            'name_contains' => Product::query()->whereLike('name', '%'.$value.'%'),
            'brand' => Product::query()->whereHas('brand', fn (Builder $q) => $q->where('name', $value)),
            'category' => Product::query()->whereHas('category', fn (Builder $q) => $q->where('name', $value)),
        };

        return $query->orderBy('id')->pluck('id')->map(fn ($id) => (string) $id)->all();
    }
}
