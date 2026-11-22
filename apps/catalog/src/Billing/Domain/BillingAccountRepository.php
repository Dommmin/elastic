<?php

namespace Billing\Domain;

use DateTimeImmutable;
use SharedKernel\ConcurrencyConflict;

interface BillingAccountRepository
{
    /**
     * Konto sprzedawcy; gdy go nie ma, zwraca świeżo otwarte (wersja 0),
     * które pierwszy `save()` utworzy.
     */
    public function getOrOpen(int $sellerId): BillingAccount;

    /**
     * Strona kont (keyset po `seller_id` rosnąco) do eksportu dla projektora.
     *
     * @return list<BillingAccount>
     */
    public function page(int $after, int $limit): array;

    /**
     * Zapisuje stan i zdarzenia integracyjne w jednej transakcji.
     *
     * @throws ConcurrencyConflict
     */
    public function save(BillingAccount $account): void;

    /**
     * Sprzedawcy z niezapłaconą, jeszcze nieoznaczoną fakturą po terminie.
     *
     * @return list<int>
     */
    public function sellerIdsWithInvoicesDueBefore(DateTimeImmutable $now): array;
}
