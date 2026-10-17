<?php

namespace SharedKernel;

/**
 * Korzeń agregatu: jedyne wejście do grupy obiektów, która zmienia się
 * razem i musi być spójna po każdej operacji.
 *
 * DLACZEGO agregat tylko ZAPISUJE zdarzenia, a ich nie publikuje:
 * domena nie zna kolejki, outboxu ani bazy. Agregat mówi "to się stało"
 * (`recordThat`), a warstwa aplikacji — po udanym zapisie — odbiera
 * zdarzenia i dopiero wtedy je rozsyła. Gdyby agregat publikował sam,
 * zdarzenie mogłoby wyjść na świat, a zapis stanu się wycofać.
 *
 * DLACZEGO dwie metody odczytu:
 * - `pendingEvents()` tylko PODGLĄDA — repozytorium potrzebuje zdarzeń,
 *   żeby zapisać je do outboxu w tej samej transakcji co stan, ale
 *   zapis może się jeszcze nie udać, więc nic nie wolno czyścić.
 * - `releaseEvents()` zwraca i CZYŚCI — woła go handler dopiero po
 *   udanym zapisie, dzięki czemu to samo zdarzenie nie poleci dwa razy.
 */
abstract class AggregateRoot
{
    /** @var list<DomainEvent> */
    private array $events = [];

    protected function recordThat(DomainEvent $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @return list<DomainEvent>
     */
    public function pendingEvents(): array
    {
        return $this->events;
    }

    /**
     * @return list<DomainEvent>
     */
    public function releaseEvents(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }
}
