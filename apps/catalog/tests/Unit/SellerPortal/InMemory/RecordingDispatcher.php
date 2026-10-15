<?php

namespace Tests\Unit\SellerPortal\InMemory;

use SellerPortal\Application\Port\DomainEventDispatcher;
use SellerPortal\Domain\Shared\DomainEvent;

final class RecordingDispatcher implements DomainEventDispatcher
{
    /** @var list<DomainEvent> */
    public array $dispatched = [];

    public function dispatch(array $events): void
    {
        array_push($this->dispatched, ...$events);
    }

    /**
     * @return list<class-string<DomainEvent>>
     */
    public function classes(): array
    {
        return array_map(fn (DomainEvent $event): string => $event::class, $this->dispatched);
    }
}
