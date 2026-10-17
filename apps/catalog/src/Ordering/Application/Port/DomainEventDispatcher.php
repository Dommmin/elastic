<?php

namespace Ordering\Application\Port;

use SharedKernel\DomainEvent;

/**
 * Port rozsyłania zdarzeń `Order` do słuchaczy w procesie (process manager).
 * Wołany WEWNĄTRZ transakcji, po udanym zapisie: słuchacz wstawia job sagi
 * atomowo ze stanem zamówienia (outbox komend).
 */
interface DomainEventDispatcher
{
    /**
     * @param  list<DomainEvent>  $events
     */
    public function dispatch(array $events): void;
}
