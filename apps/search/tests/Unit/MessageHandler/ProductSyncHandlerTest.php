<?php

namespace App\Tests\Unit\MessageHandler;

use App\Message\IntegrationEvent;
use App\MessageHandler\ProductSyncHandler;
use App\Repository\ProcessedEventRepository;
use App\Service\CatalogProjectionClient;
use App\Service\ElasticsearchIndexer;
use App\Service\IndexOutcome;
use App\Service\ProductProjection;
use App\Service\ProductSynchronizer;
use App\Service\ProjectionUnavailableException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

/**
 * Wersja dokumentu ES to `projected_at_us` z projekcji, nigdy `sequence`
 * zdarzenia (docs/superpowers/specs/2026-10-11-panel-edycji-design.md,
 * część 1). Usunięcie nie ma osobnej ścieżki: wynika z 404 projekcji.
 */
final class ProductSyncHandlerTest extends TestCase
{
    private CatalogProjectionClient&MockObject $catalogClient;
    private ElasticsearchIndexer&MockObject $indexer;
    private ProductSyncHandler $handler;

    protected function setUp(): void
    {
        $processedEvents = $this->createStub(ProcessedEventRepository::class);
        $processedEvents->method('tryMarkProcessed')->willReturn(true);

        $this->catalogClient = $this->createMock(CatalogProjectionClient::class);
        $this->indexer = $this->createMock(ElasticsearchIndexer::class);
        $this->handler = new ProductSyncHandler(
            $processedEvents,
            new ProductSynchronizer($this->catalogClient, $this->indexer),
            new NullLogger(),
        );
    }

    public function test_zmiana_ceny_oferty_indeksuje_z_wersja_rowna_czasowi_odczytu(): void
    {
        $data = ['id' => 7, 'name' => 'Produkt', 'price_min' => 1999];
        $this->catalogClient->expects(self::once())->method('fetchProductProjection')->with('7')
            ->willReturn(new ProductProjection($data, 1790000000000123));
        $this->indexer->expects(self::once())->method('indexProduct')->with($data, 1790000000000123)
            ->willReturn(IndexOutcome::Indexed);
        $this->indexer->expects(self::never())->method('deleteProduct');

        ($this->handler)($this->event('offer.price_changed', 'offer', '4', ['product_id' => 7]));
    }

    public function test_usuniecie_produktu_kasuje_z_wersja_z_odpowiedzi_404(): void
    {
        $this->catalogClient->expects(self::once())->method('fetchProductProjection')->with('7')
            ->willReturn(new ProductProjection(null, 1790000000000456));
        $this->indexer->expects(self::once())->method('deleteProduct')->with('7', 1790000000000456)
            ->willReturn(IndexOutcome::Indexed);
        $this->indexer->expects(self::never())->method('indexProduct');

        ($this->handler)($this->event('product.deleted', 'product', '7'));
    }

    public function test_niedostepna_projekcja_konczy_sie_ponowieniem(): void
    {
        $this->catalogClient->expects(self::once())->method('fetchProductProjection')
            ->willThrowException(new ProjectionUnavailableException('brak projected_at_us'));
        $this->indexer->expects(self::never())->method('indexProduct');
        $this->indexer->expects(self::never())->method('deleteProduct');

        $this->expectException(RecoverableMessageHandlingException::class);

        ($this->handler)($this->event('offer.price_changed', 'offer', '4', ['product_id' => 7]));
    }

    /** @param array<string, mixed> $data */
    private function event(string $type, string $aggregateType, string $aggregateId, array $data = []): IntegrationEvent
    {
        return new IntegrationEvent(
            id: '01TESTHANDLER00000000000000',
            type: $type,
            version: 1,
            source: 'catalog',
            occurredAt: new \DateTimeImmutable(),
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            // Celowo niski numer: drugi agregat tego samego dokumentu —
            // nie może mieć wpływu na wersję w ES.
            sequence: 1,
            data: $data,
        );
    }
}
