<?php

namespace App\Tests\Unit\Messenger;

use App\Message\IntegrationEvent;
use App\Messenger\ExternalJsonEnvelopeSerializer;
use App\Messenger\TraceIdStamp;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

final class ExternalJsonEnvelopeSerializerTraceTest extends TestCase
{
    private const TRACE = '4bf92f3577b34da6a3ce929d0e0e4736';

    private function body(): string
    {
        return json_encode([
            'id' => 'e1', 'type' => 'product.created', 'version' => 1, 'source' => 'catalog',
            'occurred_at' => '2026-10-17T10:00:00+00:00',
            'aggregate' => ['type' => 'product', 'id' => 7],
            'sequence' => 3, 'data' => ['a' => 1],
        ], JSON_THROW_ON_ERROR);
    }

    public function test_decode_dodaje_stamp_z_naglowka(): void
    {
        $envelope = (new ExternalJsonEnvelopeSerializer)->decode([
            'body' => $this->body(),
            'headers' => ['trace_id' => self::TRACE],
        ]);

        $this->assertInstanceOf(IntegrationEvent::class, $envelope->getMessage());
        $this->assertSame(self::TRACE, $envelope->last(TraceIdStamp::class)?->traceId);
    }

    public function test_decode_bez_naglowka_nie_dodaje_stampa(): void
    {
        $envelope = (new ExternalJsonEnvelopeSerializer)->decode(['body' => $this->body(), 'headers' => []]);

        $this->assertInstanceOf(IntegrationEvent::class, $envelope->getMessage());
        $this->assertNull($envelope->last(TraceIdStamp::class));
    }

    public function test_decode_z_niepoprawnym_naglowkiem_nie_dodaje_stampa(): void
    {
        $envelope = (new ExternalJsonEnvelopeSerializer)->decode([
            'body' => $this->body(),
            'headers' => ['trace_id' => 'nie-hex'],
        ]);

        $this->assertInstanceOf(IntegrationEvent::class, $envelope->getMessage());
        $this->assertNull($envelope->last(TraceIdStamp::class));
    }

    public function test_encode_zachowuje_trace_id_po_retry(): void
    {
        $serializer = new ExternalJsonEnvelopeSerializer;
        $decoded = $serializer->decode(['body' => $this->body(), 'headers' => ['trace_id' => self::TRACE]]);

        $this->assertSame(self::TRACE, $serializer->encode($decoded)['headers']['trace_id']);
    }

    public function test_encode_bez_stampa_nie_ma_naglowka(): void
    {
        $serializer = new ExternalJsonEnvelopeSerializer;
        $decoded = $serializer->decode(['body' => $this->body(), 'headers' => []]);

        $this->assertArrayNotHasKey('trace_id', $serializer->encode($decoded)['headers']);
    }

    public function test_licznik_ponowien_dziala_razem_z_trace_id(): void
    {
        $serializer = new ExternalJsonEnvelopeSerializer;
        $decoded = $serializer->decode(['body' => $this->body(), 'headers' => [
            ExternalJsonEnvelopeSerializer::RETRY_COUNT_HEADER => '2',
            'trace_id' => self::TRACE,
        ]]);

        $this->assertSame(2, RedeliveryStamp::getRetryCountFromEnvelope($decoded));
        $headers = $serializer->encode($decoded)['headers'];
        $this->assertSame('2', $headers[ExternalJsonEnvelopeSerializer::RETRY_COUNT_HEADER]);
        $this->assertSame(self::TRACE, $headers['trace_id']);
    }
}
