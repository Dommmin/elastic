<?php

namespace App\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Niesie trace_id (32 znaki [0-9a-f]) z nagłówka AMQP przez cały cykl życia
 * wiadomości w Messengerze: od decode(), przez handler, aż po encode() przy
 * retry. Dzięki temu logi search i żądanie do catalog dzielą ślad z żądaniem
 * HTTP, które wygenerowało zdarzenie w Laravelu.
 */
final class TraceIdStamp implements StampInterface
{
    public function __construct(public readonly string $traceId) {}
}
