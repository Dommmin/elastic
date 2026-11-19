<?php

namespace App\Service;

/**
 * Wynik ProductSynchronizer::sync(): co zrobiono z dokumentem (zapis albo
 * usunięcie) i z jaką wersją. `outcome` Stale = ES miał już nowszą wersję
 * (409), NotFound = usuwanego dokumentu nie było (404) — oba to sukces.
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
