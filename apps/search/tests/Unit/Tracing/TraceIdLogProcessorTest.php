<?php

namespace App\Tests\Unit\Tracing;

use App\Tracing\TraceContext;
use App\Tracing\TraceIdLogProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class TraceIdLogProcessorTest extends TestCase
{
    private function record(): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable, 'app', Level::Info, 'x');
    }

    public function test_dodaje_trace_id_gdy_ustawiony_i_usuwa_po_clear(): void
    {
        $trace = new TraceContext;
        $processor = new TraceIdLogProcessor($trace);

        $trace->set('4bf92f3577b34da6a3ce929d0e0e4736');
        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $processor($this->record())->extra['trace_id']);

        $trace->clear();
        $this->assertArrayNotHasKey('trace_id', $processor($this->record())->extra);
    }
}
