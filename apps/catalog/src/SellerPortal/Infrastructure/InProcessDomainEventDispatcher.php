<?php

namespace SellerPortal\Infrastructure;

use SellerPortal\Application\Port\DomainEventDispatcher;
use SharedKernel\DomainEvent;

/**
 * Rozsyłanie zdarzeń w procesie, synchronicznie, w kolejności rejestracji.
 *
 * DLACZEGO własny dispatcher, a nie zdarzenia Laravela: słuchacze mają
 * biec w tej samej transakcji co handler i mogą rzucić wyjątek, który
 * wycofa zapis; do tego wiążemy je jawnie po klasie zdarzenia. Zdarzenie
 * bez słuchaczy jest po prostu ignorowane.
 *
 * Rejestracja słuchaczy w providerze, więc instancja jest `scoped`
 * (per request w Octane), a nie współdzielona między żądaniami.
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
