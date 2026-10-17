<?php

namespace App\Services\Panel;

use App\Models\Outbox;
use App\Models\Product;
use App\Services\CategoryLabels;
use App\Services\Search\IndexedProductReader;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Elastic\Transport\Exception\TransportException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * „Droga zdarzenia” na stronie edycji produktu: zapisane (wiersz outboxu)
 * → opublikowane (`published_at`) → w ES (`_version` dokumentu ≥ chwila
 * zdarzenia). `_version` to `projected_at_us`, czyli czas Postgresa w µs
 * z chwili odczytu projekcji, więc wersja nie starsza niż `occurred_at`
 * znaczy, że ES widział stan po tym zdarzeniu.
 *
 * Znane okno niedokładności: `occurred_at` ustawia PHP w transakcji, przed
 * commitem. Projekcja wywołana innym zdarzeniem w tych milisekundach może
 * zapalić krok „w ES” chwilę za wcześnie; własne zdarzenie za moment
 * i tak da nowszą wersję z tą zmianą.
 */
class EventTrace
{
    private const EVENT_LIMIT = 10;

    public function __construct(private readonly IndexedProductReader $reader) {}

    /**
     * @return array{
     *     es_available: bool,
     *     events: list<array{id: int, type: string, occurred_at: string, published: bool, in_es: bool|null}>,
     *     comparison: list<array{field: string, postgres: mixed, es: mixed, differs: bool}>,
     *     pending: bool
     * }
     */
    public function forProduct(Product $product): array
    {
        $esAvailable = true;
        $document = null;

        try {
            $document = $this->reader->find($product->id);
        } catch (TransportException|ServerResponseException|ClientResponseException $e) {
            // Strona edycji ma działać bez ES: krok „w ES” jest wtedy nieznany,
            // a polling staje (pending false), zamiast co sekundę bić w martwy klaster.
            Log::warning('Droga zdarzenia: ES nieosiągalny', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);
            $esAvailable = false;
        }

        $esVersion = $document['version'] ?? null;

        $events = $this->recentEvents($product)
            ->map(fn (Outbox $event): array => [
                'id' => $event->id,
                'type' => $event->event_type,
                'occurred_at' => $event->occurred_at->toIso8601ZuluString('microsecond'),
                'published' => $event->published_at !== null,
                // Brak dokumentu (404) = jeszcze nie w ES, a nie „nie wiadomo”.
                'in_es' => $esAvailable
                    ? $esVersion !== null && $esVersion >= (int) $event->occurred_at->format('Uu')
                    : null,
            ])
            ->all();

        return [
            'es_available' => $esAvailable,
            'events' => $events,
            'comparison' => $this->comparison($product, $esAvailable, $document['source'] ?? null),
            'pending' => $esAvailable && in_array(false, array_column($events, 'in_es'), true),
        ];
    }

    /**
     * @return Collection<int, Outbox>
     */
    private function recentEvents(Product $product): Collection
    {
        // Wszystkie oferty, także nieaktywne: dezaktywacja to też zdarzenie tego produktu.
        $offerIds = $product->offers()->pluck('id')->map(fn (int $id): string => (string) $id)->all();

        return Outbox::query()
            ->where(fn ($query) => $query
                ->where('aggregate_type', 'product')
                ->where('aggregate_id', (string) $product->id))
            ->when($offerIds !== [], fn ($query) => $query->orWhere(fn ($query) => $query
                ->where('aggregate_type', 'offer')
                ->whereIn('aggregate_id', $offerIds)))
            ->orderByDesc('id')
            ->limit(self::EVENT_LIMIT)
            ->get(['id', 'event_type', 'occurred_at', 'published_at']);
    }

    /**
     * Strona Postgresa liczona jak w `ProductProjectionController`: tylko
     * widoczne oferty (`Offer::listed()`), najniższa cena i „jakikolwiek stan > 0”.
     * Bez ES nie ma z czym porównać, więc nic nie jest wyróżnione; brak
     * dokumentu (404) to już różnica w każdym polu.
     *
     * @param  array<string, mixed>|null  $source
     * @return list<array{field: string, postgres: mixed, es: mixed, differs: bool}>
     */
    private function comparison(Product $product, bool $esAvailable, ?array $source): array
    {
        $product->loadMissing('category');
        $listedOffers = $product->listedOffers()->withAvailable()->get();

        $postgresPath = $product->category?->path;
        $esPath = $source['category']['path'] ?? null;
        $labels = CategoryLabels::forPaths(array_filter([$postgresPath, $esPath], fn ($path) => $path !== null));

        $postgres = [
            'name' => $product->name,
            'category' => $postgresPath !== null ? $labels[$postgresPath] : null,
            'price_min' => $listedOffers->isNotEmpty() ? (int) $listedOffers->min('price_cents') : null,
            'in_stock' => $listedOffers->contains(fn ($offer) => $offer->available > 0),
        ];

        $es = $source === null ? array_fill_keys(array_keys($postgres), null) : [
            'name' => $source['name'] ?? null,
            'category' => $esPath !== null ? $labels[$esPath] : null,
            'price_min' => isset($source['price_min']) ? (int) $source['price_min'] : null,
            'in_stock' => isset($source['in_stock']) ? (bool) $source['in_stock'] : null,
        ];

        return collect($postgres)
            ->map(fn (mixed $value, string $field): array => [
                'field' => $field,
                'postgres' => $value,
                'es' => $es[$field],
                'differs' => $esAvailable && $value !== $es[$field],
            ])
            ->values()
            ->all();
    }
}
