<?php

namespace App\Tracing;

/**
 * "Bieżący ślad" procesu workera. Worker przetwarza wiadomości po jednej,
 * więc zwykłe pole wystarczy — nie ma współbieżności w obrębie procesu.
 * Zapełnia go TraceContextSubscriber przy odbiorze wiadomości i czyści po
 * jej obsłudze; czytają procesor logów i CatalogProjectionClient.
 */
final class TraceContext
{
    private ?string $traceId = null;

    public function set(?string $traceId): void
    {
        $this->traceId = $traceId;
    }

    public function get(): ?string
    {
        return $this->traceId;
    }

    public function clear(): void
    {
        $this->traceId = null;
    }
}
