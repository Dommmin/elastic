<?php

namespace Ordering\Infrastructure;

use Ordering\Application\Port\DomainEventDispatcher;
use SharedKernel\DomainEvent;

/**
 * Rozsyłanie zdarzeń `Order` w procesie, synchronicznie, w kolejności rejestracji.
 *
 * DLACZEGO własny dispatcher (a nie ten z portalu): Ordering nie importuje
 * portalu poza adapterami ACL, a słuchacze mają biec w transakcji handlera
 * i mogą rzucić wyjątek, który wycofa zapis razem z jobem. Zdarzenie bez
 * słuchaczy jest ignorowane (np. `OrderShipped`).
 *
 * Instancja jest `scoped` (per żądanie w Octane): słuchacze rejestruje fabryka
 * w providerze, więc nie gromadzą się w długo żyjącym workerze.
 */
final class InProcessDomainEventDispatcher implements DomainEventDispatcher
{
    /** @var array<class-string<DomainEvent>, list<callable(DomainEvent): void>> */
    private array $listeners = [];

    /**
     * @param  class-string<DomainEvent>  $eventClass
     * @param  callable(DomainEvent): void  $listener
     */
    public function listen(string $eventClass, callable $listener): void
    {
        $this->listeners[$eventClass][] = $listener;
    }

    public function dispatch(array $events): void
    {
        foreach ($events as $event) {
            foreach ($this->listeners[$event::class] ?? [] as $listener) {
                $listener($event);
            }
        }
    }
}
