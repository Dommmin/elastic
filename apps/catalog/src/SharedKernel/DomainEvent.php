<?php

namespace SharedKernel;

use DateTimeImmutable;

/**
 * Fakt, który już się wydarzył w domenie (czas przeszły w nazwie klasy).
 *
 * DLACZEGO `occurredAt()` zwraca czas przekazany z zewnątrz, a nie `now()`:
 * domena nie ma dostępu do zegara (każda metoda zmieniająca stan dostaje
 * `$now`), więc testy są deterministyczne, a zdarzenia nie różnią się o
 * mikrosekundy od zapisanego stanu agregatu.
 */
interface DomainEvent
{
    public function occurredAt(): DateTimeImmutable;
}
