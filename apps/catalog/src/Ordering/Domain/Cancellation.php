<?php

namespace Ordering\Domain;

/**
 * Kto anulował i dlaczego. Powód podaje tylko sprzedawca: anuluje opłacone
 * zamówienie, więc kupujący musi wiedzieć, czemu dostaje zwrot.
 */
final readonly class Cancellation
{
    public function __construct(
        public CancelledBy $by,
        public ?Reason $reason,
    ) {}
}
