<?php

namespace App\Support\ErrorTracking;

use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Throwable;
use WeakMap;

/**
 * Rozpoznaje wyjątek zadania, po którym zadanie zostanie ponowione.
 *
 * Laravel zgłasza do handlera wyjątek KAŻDEJ próby zadania, a przejściowa
 * porażka (zadania sagi mają `tries` 5–10 z `backoff`) nie jest błędem do
 * naprawy: robiłaby szum w GlitchTipie i w logu. Do issue ma trafić dopiero
 * ostatnia, nieudana próba.
 *
 * Worker robi to w stałej kolejności: `JobExceptionOccurred`, potem, jeśli
 * to ostatnia próba, `JobFailed`, i dopiero na końcu `report($e)`. Obie
 * informacje wiążemy z OBIEKTEM wyjątku (WeakMap), nie z flagą procesu.
 * Dzięki temu nic nie wycieka między zadaniami, a wyjątek spoza zadania
 * (pętla workera, żądanie HTTP w tym samym procesie) nigdy nie jest wyciszony.
 */
final class QueueRetryFilter
{
    /** @var WeakMap<Throwable, true> */
    private WeakMap $occurred;

    /** @var WeakMap<Throwable, true> */
    private WeakMap $failedFinally;

    public function __construct()
    {
        $this->occurred = new WeakMap;
        $this->failedFinally = new WeakMap;
    }

    public function jobExceptionOccurred(JobExceptionOccurred $event): void
    {
        $this->occurred[$event->exception] = true;
    }

    public function jobFailed(JobFailed $event): void
    {
        $this->failedFinally[$event->exception] = true;
    }

    public function willRetry(Throwable $exception): bool
    {
        return isset($this->occurred[$exception]) && ! isset($this->failedFinally[$exception]);
    }
}
