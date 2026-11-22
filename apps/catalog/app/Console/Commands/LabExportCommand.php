<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use Elastic\Elasticsearch\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * `php artisan search:lab:export` — próbka katalogu dla es-lab (ETAP 14): co 15. produkt
 * (`id % MODULO = 0`) jako NDJSON w kształcie mapowania `products-lab-v1`.
 *
 * DLACZEGO `in_stock` i `price_min` z indeksu, a nie z bazy: dostępność to wynik
 * projekcji (oferty minus rezerwacje), a ewaluacja laboratoryjna ma oceniać to, co
 * widzi wyszukiwarka na produkcji. Brak produktu próbki w indeksie to błąd —
 * cicha dziura w próbce zafałszowałaby wszystkie późniejsze liczby.
 */
class LabExportCommand extends Command
{
    /** Jedyne źródło M próbki; ewaluacja (search:lab:eval) i setup-lab.sh czytają je stąd/z nagłówka pliku. */
    public const MODULO = 15;

    private const ALIAS = 'products-search';

    private const CHUNK = 1000;

    protected $signature = 'search:lab:export {--modulo=15 : Co który produkt (id % M = 0)} {--out=lab/products.ndjson : Ścieżka na dysku local (storage/app/private)}';

    protected $description = 'Eksportuje próbkę produktów (id % M = 0) do NDJSON dla indeksu products-lab-v1';

    public function __construct(private readonly Client $client)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $modulo = max(1, (int) $this->option('modulo'));
        $out = (string) $this->option('out');
        $started = microtime(true);

        $body = fopen('php://temp', 'w+b');
        $count = 0;
        $missing = [];
        /** @var array<int, Category> $categories */
        $categories = [];

        Product::query()
            ->with(['brand', 'category'])
            ->whereRaw('id % ? = 0', [$modulo])
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($products) use ($body, &$count, &$missing, &$categories) {
                $docs = $this->fetchIndexDocs($products->pluck('id')->all());

                foreach ($products as $product) {
                    $indexed = $docs[$product->id] ?? null;
                    if ($indexed === null) {
                        $missing[] = $product->id;

                        continue;
                    }

                    fwrite($body, json_encode($this->exportRow($product, $indexed, $categories), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
                    $count++;
                }
            });

        if ($missing !== []) {
            fclose($body);
            $this->error(sprintf(
                'Produkty z próbki nie istnieją w indeksie %s (%d): %s. Zsynchronizuj indeks (reindex/backfill) i powtórz eksport.',
                self::ALIAS,
                count($missing),
                implode(', ', array_slice($missing, 0, 20)).(count($missing) > 20 ? ', ...' : ''),
            ));

            return self::FAILURE;
        }

        $header = json_encode(['_lab_export' => ['modulo' => $modulo, 'count' => $count, 'exported_at' => now()->toIso8601String()]]);
        $file = fopen('php://temp', 'w+b');
        fwrite($file, $header."\n");
        rewind($body);
        stream_copy_to_stream($body, $file);
        rewind($file);
        Storage::disk('local')->put($out, $file);
        fclose($body);
        fclose($file);

        $this->info(sprintf('Wyeksportowano %d produktów (modulo %d) do %s w %.1f s.', $count, $modulo, $out, microtime(true) - $started));

        return self::SUCCESS;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>> id => _source
     */
    private function fetchIndexDocs(array $ids): array
    {
        $response = $this->client->mget([
            'index' => self::ALIAS,
            'body' => ['ids' => array_map('strval', $ids)],
            '_source' => ['in_stock', 'price_min'],
        ]);

        $found = [];
        foreach ($response['docs'] ?? [] as $doc) {
            if (($doc['found'] ?? false) === true) {
                $found[(int) $doc['_id']] = $doc['_source'] ?? [];
            }
        }

        return $found;
    }

    /**
     * @param  array<string, mixed>  $indexed
     * @param  array<int, Category>  $categories  pamięć podręczna korzeni (taksonomia ma kilkadziesiąt wierszy)
     * @return array<string, mixed>
     */
    private function exportRow(Product $product, array $indexed, array &$categories): array
    {
        $leaf = $product->category;
        $root = $leaf === null ? null : $this->root($leaf, $categories);
        $leafName = $leaf?->name;
        $rootName = $root?->name;

        $names = array_values(array_unique(array_filter([$leafName, $rootName])));
        $labText = $product->name.($names === [] ? '' : '. '.implode(', ', $names));

        return [
            'id' => (string) $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'brand' => $product->brand?->name,
            'category' => $leaf ? ['id' => (string) $leaf->id, 'path' => $leaf->path] : null,
            'category_leaf' => $leafName,
            'category_root' => $rootName,
            'in_stock' => (bool) ($indexed['in_stock'] ?? false),
            'price_min' => $indexed['price_min'] ?? null,
            'lab_text' => $labText,
        ];
    }

    /** @param  array<int, Category>  $cache */
    private function root(Category $category, array &$cache): Category
    {
        $current = $category;
        for ($depth = 0; $current->parent_id !== null && $depth < 10; $depth++) {
            $current = $cache[$current->parent_id] ??= Category::query()->findOrFail($current->parent_id);
        }

        return $current;
    }
}
