<?php

namespace App\MessageHandler;

use App\Message\IntegrationEvent;
use App\Repository\ProcessedEventRepository;
use App\Service\CatalogProjectionClient;
use App\Service\ElasticsearchIndexer;
use App\Service\IndexOutcome;
use App\Service\ProjectionUnavailableException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

/**
 * Konsument kolejki `search.product.sync` (docs/02-APLIKACJE.md, katalog
 * zdarzeń: product.*, offer.*, review.created; seller.updated i
 * category.renamed jako fan-out — patrz TODO niżej, poza zakresem ETAPU 6).
 *
 * `fromTransport: 'product_sync'` — NIE typ wiadomości — decyduje, że to
 * WŁAŚNIE ten handler dostanie IntegrationEvent z tego transportu (ten sam
 * typ wiadomości płynie też przez `analytics` i `alerts`, do INNYCH handlerów).
 */
#[AsMessageHandler(fromTransport: 'product_sync')]
final class ProductSyncHandler
{
    public function __construct(
        private readonly ProcessedEventRepository $processedEvents,
        private readonly CatalogProjectionClient $catalogClient,
        private readonly ElasticsearchIndexer $indexer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(IntegrationEvent $event): void
    {
        // Idempotencja (docs/05-SPOJNOSC-DANYCH.md, sekcja 3, [4]).
        // RabbitMQ gwarantuje AT-LEAST-ONCE — redelivery po padzie tego
        // procesu w połowie przetwarzania jest pewnością, nie ryzykiem.
        if (! $this->processedEvents->tryMarkProcessed($event->id, self::class)) {
            $this->logger->debug('Duplikat zdarzenia, pomijam.', ['event_id' => $event->id, 'type' => $event->type]);

            return;
        }

        $productId = $this->resolveProductId($event);

        if ($productId === null) {
            // seller.updated / category.renamed: fan-out na wiele produktów
            // (update_by_query) — świadomie POZA zakresem ETAPU 6, patrz
            // docs/03-SCIEZKA-NAUKI.md moduł 5. Na razie tylko logujemy,
            // żeby było widać, że event dotarł i został POPRAWNIE rozpoznany,
            // nie zgubiony po cichu.
            $this->logger->warning(
                'Fan-out dla tego typu zdarzenia nie jest jeszcze zaimplementowany (ETAP 6).',
                ['event_id' => $event->id, 'type' => $event->type, 'aggregate_type' => $event->aggregateType],
            );

            return;
        }

        // Także product.deleted idzie przez projekcję: o usunięciu decyduje
        // 404 z catalogu, a jego ciało niesie wersję (czas odczytu). Gdyby
        // produkt w międzyczasie wrócił, świeższy odczyt i tak wygra.
        $this->syncProduct($productId, $event);
    }

    /**
     * Dla zdarzeń produktowych aggregate_id JEST id produktu. Dla zdarzeń
     * oferty i opinii aggregate_id to id OFERTY/OPINII — id produktu trzeba
     * wziąć z payloadu eventu (zawsze tam jest, bo offers/reviews mają FK
     * product_id).
     */
    private function resolveProductId(IntegrationEvent $event): ?string
    {
        return match ($event->aggregateType) {
            'product' => $event->aggregateId,
            'offer', 'review' => isset($event->data['product_id'])
                ? (string) $event->data['product_id']
                : null,
            default => null,
        };
    }

    private function syncProduct(string $productId, IntegrationEvent $event): void
    {
        try {
            $projection = $this->catalogClient->fetchProductProjection($productId);
        } catch (ProjectionUnavailableException $e) {
            // Laravel odpowiedziało 5xx/timeout albo bez projected_at_us —
            // PRZEJŚCIOWE, ma sens ponowić; wersji dokumentu nie zgadujemy.
            // forceRetry: false (RUNBOOK #027) — domyślne true w Symfony 8
            // każe ponawiać BEZ LIMITU, ignorując retry_strategy.max_retries.
            throw new RecoverableMessageHandlingException($e->getMessage(), previous: $e, forceRetry: false);
        }

        // Wersja dokumentu = czas odczytu projekcji, NIE $event->sequence
        // (licznik jednego agregatu — patrz docblock ElasticsearchIndexer).
        if ($projection->exists()) {
            $outcome = $this->indexer->indexProduct($projection->data, $projection->projectedAtUs);
        } else {
            // Produkt nie istnieje: product.deleted albo zniknął między
            // zdarzeniem a przetworzeniem (np. usunięty tuż po offer.updated).
            $outcome = $this->indexer->deleteProduct($productId, $projection->projectedAtUs);
        }

        $this->logOutcome($outcome, $productId, $event, $projection->projectedAtUs);
    }

    private function logOutcome(IndexOutcome $outcome, string $productId, IntegrationEvent $event, int $docVersion): void
    {
        match ($outcome) {
            IndexOutcome::Indexed => $this->logger->info(
                'Zaindeksowano produkt.',
                ['product_id' => $productId, 'event_type' => $event->type, 'sequence' => $event->sequence, 'doc_version' => $docVersion],
            ),
            // 409/404 z external versioning to SUKCES mechanizmu, nie błąd —
            // docs/05-SPOJNOSC-DANYCH.md, sekcja 3, [5]. Log na poziomie
            // debug, nie error/warning — inaczej ktoś kiedyś doda na to
            // alertowanie i będzie budzony w nocy przez coś, co działa
            // dokładnie tak, jak powinno.
            IndexOutcome::Stale => $this->logger->debug(
                'Pominięto nieaktualne zdarzenie (nowsza wersja już zaindeksowana).',
                ['product_id' => $productId, 'event_type' => $event->type, 'sequence' => $event->sequence, 'doc_version' => $docVersion],
            ),
        };
    }
}
