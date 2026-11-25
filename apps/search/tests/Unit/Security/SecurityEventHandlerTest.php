<?php

namespace App\Tests\Unit\Security;

use App\Analytics\AnalyticsBulkWriter;
use App\Analytics\BulkOutcome;
use App\Message\IntegrationEvent;
use App\MessageHandler\SecurityEventHandler;
use App\Security\EventPercolator;
use App\Security\SecurityDocumentMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Handler\Acknowledger;

final class SecurityEventHandlerTest extends TestCase
{
    /** @var array<string, \Throwable|string|null> */
    private array $results = [];

    private function handler(AnalyticsBulkWriter $writer, EventPercolator $percolator): SecurityEventHandler
    {
        return new SecurityEventHandler(new SecurityDocumentMapper(), $writer, $percolator, new NullLogger());
    }

    /** @param array<string, mixed> $data */
    private function feed(SecurityEventHandler $handler, string $id, string $type, array $data): void
    {
        $event = new IntegrationEvent($id, $type, 1, 'catalog', new \DateTimeImmutable('2026-11-25T10:00:00Z'), 'offer', '42', 1, $data);
        $handler($event, new Acknowledger('test', function (?\Throwable $e) use ($id): void {
            $this->results[$id] = $e ?? 'ack';
        }));
    }

    /** @return array<string, mixed> */
    private static function price(): array
    {
        return ['product_id' => 7, 'seller_id' => 5, 'old_price_cents' => 100, 'new_price_cents' => 120];
    }

    public function test_sukces_i_duplikat_to_ack_a_percolacja_dostaje_referencje_zapisanych(): void
    {
        $writer = $this->createMock(AnalyticsBulkWriter::class);
        $writer->expects(self::once())->method('write')->willReturn(new BulkOutcome(created: 1, duplicates: 1));
        $percolator = $this->createMock(EventPercolator::class);
        $percolator->expects(self::once())->method('percolate')->with(['events-security-default/a', 'events-security-default/b'])->willReturn(0);
        $handler = $this->handler($writer, $percolator);

        $this->feed($handler, 'a', 'offer.price_changed', self::price());
        $this->feed($handler, 'b', 'offer.price_changed', self::price());
        $handler->flush(true);

        self::assertSame(['a' => 'ack', 'b' => 'ack'], $this->results);
    }

    public function test_blad_percolacji_to_nack_recoverable_zapisanych_bez_wymuszania_retry(): void
    {
        $writer = $this->createStub(AnalyticsBulkWriter::class);
        $writer->method('write')->willReturn(new BulkOutcome(
            created: 1,
            rejected: [BulkOutcome::ref('events-security-default', 'b')],
        ));
        $percolator = $this->createStub(EventPercolator::class);
        $percolator->method('percolate')->willThrowException(new \RuntimeException('percolator padl'));
        $handler = $this->handler($writer, $percolator);

        $this->feed($handler, 'a', 'offer.price_changed', self::price());
        $this->feed($handler, 'b', 'offer.price_changed', self::price());
        $this->feed($handler, 'x', 'seller.renamed', []);
        $handler->flush(true);

        // Zapisana wiadomość wraca do kolejki: powtórka dostanie 409 i spercoluje ponownie.
        self::assertInstanceOf(RecoverableMessageHandlingException::class, $this->results['a']);
        self::assertFalse($this->results['a']->forceRetry());
        // Odrzucona zostaje Unrecoverable, a zdarzenie spoza katalogu ack (nie czeka na percolację).
        self::assertInstanceOf(UnrecoverableMessageHandlingException::class, $this->results['b']);
        self::assertSame('ack', $this->results['x']);
    }

    public function test_ack_zapisanych_dopiero_po_udanej_percolacji(): void
    {
        $writer = $this->createStub(AnalyticsBulkWriter::class);
        $writer->method('write')->willReturn(new BulkOutcome(created: 1));
        $percolator = $this->createMock(EventPercolator::class);
        $percolator->expects(self::once())->method('percolate')->willReturnCallback(function (): int {
            self::assertSame([], $this->results, 'ack przed percolacją');

            return 1;
        });
        $handler = $this->handler($writer, $percolator);

        $this->feed($handler, 'a', 'offer.price_changed', self::price());
        $handler->flush(true);

        self::assertSame(['a' => 'ack'], $this->results);
    }

    public function test_odrzucenie_i_retryable_oraz_zly_payload_dostaja_wlasciwe_wyjatki_i_nie_sa_percolowane(): void
    {
        $writer = $this->createStub(AnalyticsBulkWriter::class);
        $writer->method('write')->willReturn(new BulkOutcome(
            rejected: [BulkOutcome::ref('events-security-default', 'a')],
            retryable: [BulkOutcome::ref('events-security-default', 'b')],
        ));
        $percolator = $this->createMock(EventPercolator::class);
        $percolator->expects(self::once())->method('percolate')->with(['events-security-default/c']);
        $handler = $this->handler($writer, $percolator);

        $this->feed($handler, 'a', 'offer.price_changed', self::price());
        $this->feed($handler, 'b', 'offer.price_changed', self::price());
        $this->feed($handler, 'c', 'offer.price_changed', self::price());
        $this->feed($handler, 'd', 'offer.price_changed', ['product_id' => [1]]);
        $handler->flush(true);

        self::assertInstanceOf(UnrecoverableMessageHandlingException::class, $this->results['a']);
        self::assertInstanceOf(RecoverableMessageHandlingException::class, $this->results['b']);
        self::assertSame('ack', $this->results['c']);
        self::assertInstanceOf(UnrecoverableMessageHandlingException::class, $this->results['d']);
    }

    public function test_blad_calego_zapisu_to_retry_calej_paczki(): void
    {
        $writer = $this->createStub(AnalyticsBulkWriter::class);
        $writer->method('write')->willThrowException(new \RuntimeException('503'));
        $percolator = $this->createMock(EventPercolator::class);
        $percolator->expects(self::never())->method('percolate');
        $handler = $this->handler($writer, $percolator);

        $this->feed($handler, 'a', 'offer.price_changed', self::price());
        $handler->flush(true);

        self::assertInstanceOf(RecoverableMessageHandlingException::class, $this->results['a']);
    }
}
