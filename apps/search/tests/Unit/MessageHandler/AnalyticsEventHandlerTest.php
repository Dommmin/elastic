<?php

namespace App\Tests\Unit\MessageHandler;

use App\Analytics\AnalyticsBulkWriter;
use App\Analytics\AnalyticsDocumentMapper;
use App\Analytics\BulkOutcome;
use App\Message\IntegrationEvent;
use App\MessageHandler\AnalyticsEventHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Handler\Acknowledger;

final class AnalyticsEventHandlerTest extends TestCase
{
    private AnalyticsEventHandler $handler;

    /** @var array<string, \Throwable|string|null> */
    private array $results = [];

    private function handlerWith(AnalyticsBulkWriter $writer): void
    {
        $this->handler = new AnalyticsEventHandler(new AnalyticsDocumentMapper(), $writer, new NullLogger());
    }

    public function test_paczka_jest_zapisywana_jednym_bulkiem_a_sukces_i_duplikat_to_ack(): void
    {
        $writer = $this->createMock(AnalyticsBulkWriter::class);
        $writer->expects(self::once())->method('write')
            ->with(self::callback(fn (array $items) => \count($items) === 3)) // searched + 1 impression + click
            ->willReturn(new BulkOutcome(created: 2, duplicates: 1));
        $this->handlerWith($writer);

        $this->feed('a', 'user.searched', ['search_id' => 's', 'results' => ['1']]);
        $this->feed('b', 'user.clicked_result', ['product_id' => 1, 'position' => 1, 'dedup_key' => 'click:s:1']);
        $this->handler->flush(true);

        $this->assertSame(['a' => 'ack', 'b' => 'ack'], $this->results);
    }

    public function test_odrzucony_impression_psuje_wiadomosc_searched_a_retryable_inna(): void
    {
        $writer = $this->createStub(AnalyticsBulkWriter::class);
        $writer->method('write')->willReturn(new BulkOutcome(
            created: 1,
            rejected: [BulkOutcome::ref('events-impression-default', 's:1')],
            retryable: [BulkOutcome::ref('events-user-default', 'click:s:1')],
        ));
        $this->handlerWith($writer);

        $this->feed('a', 'user.searched', ['search_id' => 's', 'results' => ['1']]);
        $this->feed('b', 'user.clicked_result', ['product_id' => 1, 'position' => 1, 'dedup_key' => 'click:s:1']);
        $this->feed('c', 'user.added_to_cart', ['product_id' => 1]);
        $this->handler->flush(true);

        $this->assertInstanceOf(UnrecoverableMessageHandlingException::class, $this->results['a']);
        $this->assertInstanceOf(RecoverableMessageHandlingException::class, $this->results['b']);
        $this->assertSame('ack', $this->results['c']);
    }

    public function test_nieznany_typ_jest_ackowany_bez_zapisu(): void
    {
        $writer = $this->createMock(AnalyticsBulkWriter::class);
        $writer->expects(self::once())->method('write')->with([])->willReturn(new BulkOutcome());
        $this->handlerWith($writer);

        $this->feed('a', 'product.created', []);
        $this->handler->flush(true);

        $this->assertSame(['a' => 'ack'], $this->results);
    }

    public function test_blad_calego_zapisu_wraca_do_retry_dla_calej_paczki(): void
    {
        $writer = $this->createStub(AnalyticsBulkWriter::class);
        $writer->method('write')->willThrowException(new \RuntimeException('es padł'));
        $this->handlerWith($writer);

        $this->feed('a', 'user.added_to_cart', ['product_id' => 1]);
        $this->feed('b', 'user.added_to_cart', ['product_id' => 2]);
        $this->handler->flush(true);

        $this->assertInstanceOf(RecoverableMessageHandlingException::class, $this->results['a']);
        $this->assertInstanceOf(RecoverableMessageHandlingException::class, $this->results['b']);
    }

    public function test_rozmiar_paczki_to_500(): void
    {
        $writer = $this->createMock(AnalyticsBulkWriter::class);
        $writer->expects(self::once())->method('write')->willReturn(new BulkOutcome(created: 500));
        $this->handlerWith($writer);

        for ($i = 0; $i < 499; ++$i) {
            $this->feed("m{$i}", 'user.added_to_cart', ['product_id' => 1]);
        }
        $this->assertSame([], $this->results, 'przed 500. wiadomością nic nie spływa');

        $this->feed('last', 'user.added_to_cart', ['product_id' => 1]);
        $this->assertCount(500, $this->results);
    }

    /** @param array<string, mixed> $data */
    private function feed(string $id, string $type, array $data): void
    {
        $event = new IntegrationEvent($id, $type, 1, 'catalog', new \DateTimeImmutable('2026-11-20T10:00:00Z'), 'session', 'sess', 0, $data);
        ($this->handler)($event, new Acknowledger('test', function (?\Throwable $e, mixed $result = null) use ($id): void {
            $this->results[$id] = $e ?? 'ack';
        }));
    }
}
