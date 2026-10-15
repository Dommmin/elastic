<?php

namespace SellerPortal\Application\Port;

use SellerPortal\Domain\Shared\DomainEvent;

/**
 * Port rozsyłania zdarzeń domeny do słuchaczy w procesie (polityki między
 * agregatami). Wołany przez handler WEWNĄTRZ transakcji, po udanym zapisie.
 */
interface DomainEventDispatcher
{
    /**
     * @param  list<DomainEvent>  $events
     */
    public function dispatch(array $events): void;
}
