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
use Psr\Log\LoggerInterface;
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
    private ProcessedEventRepository $processedEvents;

    protected function setUp(): void
    {
        $this->processedEvents = $this->createStub(ProcessedEventRepository::class);
        $this->processedEvents->method('tryMarkProcessed')->willReturn(true);

        $this->catalogClient = $this->createMock(CatalogProjectionClient::class);
        $this->indexer = $this->createMock(ElasticsearchIndexer::class);
        $this->handler = $this->handlerWith(new NullLogger());
    }

    private function handlerWith(LoggerInterface $logger): ProductSyncHandler
    {
        return new ProductSyncHandler(
            $this->processedEvents,
            new ProductSynchronizer($this->catalogClient, $this->indexer),
            $logger,
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

    /** @return array<string, array{string}> */
    public static function zdarzeniaSprzedawcy(): array
    {
        return array_map(
            static fn (string $t): array => [$t],
            array_combine(
                $t = ['seller.registered', 'seller.approved', 'seller.rejected', 'seller.blocked', 'seller.unblocked', 'seller.renamed', 'seller.relocated'],
                $t,
            ),
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('zdarzeniaSprzedawcy')]
    public function test_zdarzenie_sprzedawcy_jest_swiadomie_ignorowane_na_poziomie_debug(string $type): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('debug');
        $logger->expects(self::never())->method('warning');
        $this->catalogClient->expects(self::never())->method('fetchProductProjection');
        $this->indexer->expects(self::never())->method('indexProduct');

        $this->handlerWith($logger)($this->event($type, 'seller', '9'));
    }

    public function test_nieznany_typ_dalej_daje_ostrzezenie(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');
        $this->catalogClient->expects(self::never())->method('fetchProductProjection');
        $this->indexer->expects(self::never())->method('indexProduct');

        $this->handlerWith($logger)($this->event('foo.bar', 'foo', '1'));
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
