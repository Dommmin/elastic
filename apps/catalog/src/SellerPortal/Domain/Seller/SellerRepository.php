<?php

namespace SellerPortal\Domain\Seller;

use SellerPortal\Domain\Shared\ConcurrencyConflict;
use SellerPortal\Domain\Shared\UserId;

/**
 * Port: jak domena widzi "kolekcję sprzedawców". Interfejs żyje w domenie,
 * implementacja (SQL) w Infrastructure — dzięki temu domena nie wie, że istnieje baza.
 *
 * DLACZEGO `nextIdentity()` w repozytorium: tożsamość agregatu powstaje
 * przed zapisem, więc zdarzenia (np. SellerRegistered) mogą ją nieść od początku.
 */
interface SellerRepository
{
    public function nextIdentity(): SellerId;

    /**
     * @throws SellerNotFound
     */
    public function get(SellerId $id): Seller;

    public function findByUserId(UserId $userId): ?Seller;

    /**
     * @throws ConcurrencyConflict gdy ktoś zapisał agregat po jego odczycie
     */
    public function save(Seller $seller): void;
}
