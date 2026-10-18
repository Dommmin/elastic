<?php

use App\Models\Outbox;
use App\Support\Outbox\OutboxMessageFactory;
use Illuminate\Support\Carbon;

function factoryRow(?string $traceId): Outbox
{
    return new Outbox([
        'event_id' => '01JAAAAAAAAAAAAAAAAAAAAAAA',
        'aggregate_type' => 'product',
        'aggregate_id' => '7',
        'event_type' => 'product.created',
        'payload' => ['name' => 'Telefon'],
        'sequence' => 1,
        'occurred_at' => Carbon::parse('2026-10-17 12:00:00'),
        'trace_id' => $traceId,
    ]);
}

test('wiadomość niesie trace_id w nagłówku AMQP', function () {
    $msg = OutboxMessageFactory::make(factoryRow(str_repeat('c', 32)));

    expect($msg->get('application_headers')->getNativeData()['trace_id'])->toBe(str_repeat('c', 32))
        ->and($msg->get('content_type'))->toBe('application/json');
});

test('bez trace_id nie ma application_headers', function () {
    $msg = OutboxMessageFactory::make(factoryRow(null));

    expect($msg->has('application_headers'))->toBeFalse();
});

test('koperta ma dokładnie dotychczasowy kształt', function () {
    $body = json_decode(OutboxMessageFactory::make(factoryRow(null))->getBody(), true);

    expect(array_keys($body))->toBe(['id', 'type', 'version', 'source', 'occurred_at', 'aggregate', 'sequence', 'data']);
});
