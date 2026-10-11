<?php

namespace App\Services;

use App\Models\Category;

/**
 * Etykiety kategorii z `category.path` ("5.6" → "Odzież › Buty do biegania").
 * Wspólne dla facetów wyszukiwarki i panelu edycji, żeby oba miejsca
 * pokazywały tę samą nazwę.
 */
class CategoryLabels
{
    /**
     * Jedno zapytanie `whereIn` o wszystkie segmenty. Brak któregokolwiek
     * segmentu w bazie (np. kategoria usunięta, a indeks jeszcze nie
     * przeliczony) → zostaje sama ścieżka, nie połówka nazwy.
     *
     * @param  array<int, string>  $paths
     * @return array<string, string> ścieżka => etykieta
     */
    public static function forPaths(array $paths): array
    {
        $paths = array_values(array_unique(array_map('strval', $paths)));

        if ($paths === []) {
            return [];
        }

        $ids = collect($paths)
            ->flatMap(fn (string $path): array => explode('.', $path))
            ->unique()
            ->values();

        $names = Category::query()->whereIn('id', $ids)->pluck('name', 'id');

        $labels = [];
        foreach ($paths as $path) {
            $resolved = array_map(fn (string $id): ?string => $names[$id] ?? null, explode('.', $path));

            $labels[$path] = in_array(null, $resolved, true) ? $path : implode(' › ', $resolved);
        }

        return $labels;
    }
}
