<?php

namespace SellerPortal\Domain\Offer;

use SellerPortal\Domain\Seller\SellerId;
use SharedKernel\ConcurrencyConflict;

/**
 * Port: jak domena widzi "kolekcję ofert". Implementacja (SQL) żyje w Infrastructure.
 */
interface OfferRepository
{
    public function nextIdentity(): OfferId;

    /**
     * @throws OfferNotFound
     */
    public function get(OfferId $id): Offer;

    /**
     * @throws ConcurrencyConflict gdy ktoś zapisał agregat po jego odczycie
     */
    public function save(Offer $offer): void;

    /**
     * Czy sprzedawca ma już na ten produkt ofertę w statusie Draft, Published lub Suspended.
     */
    public function hasLiveOffer(SellerId $sellerId, ProductId $productId): bool;

    /**
     * Los rezerwacji, także zakończonej (agregat zna tylko aktywne). `null` = taka rezerwacja nigdy nie istniała.
     */
    public function reservationStatus(OfferId $offerId, ReservationKey $key): ?ReservationStatus;

    /**
     * Stronicowanie po ID dla polityk masowych (np. zawieszenie ofert zablokowanego
     * sprzedawcy): job przetwarza paczki zamiast ładować wszystko naraz.
     *
     * @return list<OfferId>
     */
    public function idsForSellerPolicy(SellerId $sellerId, OfferStatus $status, ?SuspensionKind $suspensionKind, int $afterId, int $limit): array;
}
