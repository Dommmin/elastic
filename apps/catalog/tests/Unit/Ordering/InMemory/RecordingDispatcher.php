<?php

namespace Tests\Unit\Ordering\InMemory;

use Ordering\Application\Port\DomainEventDispatcher;
use SharedKernel\DomainEvent;

/**
 * Zapisuje rozesłane zdarzenia i przekazuje je subskrybentom, żeby testy
 * sagi mogły podpiąć process manager tak, jak robi to dispatcher Laravela.
 */
final class RecordingDispatcher implements DomainEventDispatcher
{
    /** @var list<DomainEvent> */
    public array $dispatched = [];

    /** @var list<callable(DomainEvent): void> */
    private array $subscribers = [];

    /**
     * @param  callable(DomainEvent): void  $subscriber
     */
    public function subscribe(callable $subscriber): void
    {
        $this->subscribers[] = $subscriber;
    }

    public function dispatch(array $events): void
    {
        foreach ($events as $event) {
            $this->dispatched[] = $event;

            foreach ($this->subscribers as $subscriber) {
                $subscriber($event);
            }
        }
    }

    /**
     * @return list<class-string<DomainEvent>>
     */
    public function classes(): array
    {
        return array_map(fn (DomainEvent $event): string => $event::class, $this->dispatched);
    }
}
