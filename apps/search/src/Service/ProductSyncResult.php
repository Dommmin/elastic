<?php

namespace App\Service;

/**
 * Wynik ProductSynchronizer::sync(): co zrobiono z dokumentem (zapis albo
 * usunięcie) i z jaką wersją. `outcome` Stale = ES miał już nowszą wersję
 * (409) albo usuwanego dokumentu nie było (404) — w obu przypadkach sukces.
 */
final readonly class ProductSyncResult
{
    public function __construct(
        public IndexOutcome $outcome,
        public bool $deleted,
        public int $docVersion,
    ) {
    }
}
