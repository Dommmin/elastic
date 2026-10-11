<?php

namespace App\Service;

/**
 * Jeden produkt: pobierz projekcję z catalogu i zapisz ją w ES z wersją
 * = `projected_at_us` (albo usuń, gdy catalog odpowiada 404).
 *
 * Wspólne dla ProductSyncHandler (zdarzenie z kolejki) i ReprojectCommand
 * (jednorazowe przeliczenie wszystkich dokumentów) — obie drogi MUSZĄ
 * decydować identycznie, inaczej reproject mógłby zapisać inny dokument
 * niż ten, który zapisałby consumer po następnym zdarzeniu.
 *
 * Nie łapie ProjectionUnavailableException: handler zamienia go na
 * ponowienie wiadomości, komenda liczy jako błąd i idzie dalej.
 */
final class ProductSynchronizer
{
    public function __construct(
        private readonly CatalogProjectionClient $catalogClient,
        private readonly ElasticsearchIndexer $indexer,
    ) {
    }

    /**
     * @throws ProjectionUnavailableException
     */
    public function sync(string $productId): ProductSyncResult
    {
        $projection = $this->catalogClient->fetchProductProjection($productId);

        // Wersja dokumentu = czas odczytu projekcji, NIE $event->sequence
        // (licznik jednego agregatu — patrz docblock ElasticsearchIndexer).
        if ($projection->exists()) {
            return new ProductSyncResult(
                $this->indexer->indexProduct($projection->data, $projection->projectedAtUs),
                deleted: false,
                docVersion: $projection->projectedAtUs,
            );
        }

        // Produkt nie istnieje: product.deleted albo zniknął między
        // zdarzeniem a przetworzeniem (np. usunięty tuż po offer.updated).
        return new ProductSyncResult(
            $this->indexer->deleteProduct($productId, $projection->projectedAtUs),
            deleted: true,
            docVersion: $projection->projectedAtUs,
        );
    }
}
