<?php

namespace App\Security;

/**
 * Szew między konsumentem `events-security` a regułami percolatora (Task 5).
 * Wywoływany po udanym zapisie paczki; błąd percolacji nie cofa zapisu zdarzenia.
 */
interface EventPercolator
{
    /**
     * @param  list<string>  $refs  referencje `<indeks>/<_id>` zapisanych dokumentów (BulkOutcome::ref)
     * @return int liczba wygenerowanych alertów
     */
    public function percolate(array $refs): int;
}
