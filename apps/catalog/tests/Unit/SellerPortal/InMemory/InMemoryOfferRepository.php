<?php

namespace Tests\Unit\SellerPortal\InMemory;

use SellerPortal\Domain\Offer\Event\OfferReservationConfirmed;
use SellerPortal\Domain\Offer\Event\OfferReservationReleased;
use SellerPortal\Domain\Offer\Event\OfferStockReserved;
use SellerPortal\Domain\Offer\Offer;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Offer\OfferNotFound;
use SellerPortal\Domain\Offer\OfferRepository;
use SellerPortal\Domain\Offer\OfferStatus;
use SellerPortal\Domain\Offer\ProductId;
use SellerPortal\Domain\Offer\ReservationKey;
use SellerPortal\Domain\Offer\ReservationStatus;
use SellerPortal\Domain\Offer\SuspensionKind;
use SellerPortal\Domain\Seller\SellerId;
use SharedKernel\ConcurrencyConflict;

/**
 * Ten sam kontrakt co SqlOfferRepository (wersje, kopie, `hasLiveOffer`
 * bez wycofanych). Nie egzekwuje indeksu unikalnego: ten ma test integracyjny.
 */
final class InMemoryOfferRepository implements OfferRepository
{
    /** @var array<int, Offer> */
    private array $stored = [];

    private int $lastId = 0;

    /** @var array<string, ReservationStatus> klucz "{offerId}:{reservationKey}" */
    private array $reservationStatuses = [];

    private int $failingSaves = 0;

    /**
     * Następne `$count` zapisów rzuci ConcurrencyConflict, jak przy wyścigu o wersję (test ponowień).
     */
    public function failNextSaves(int $count): void
    {
        $this->failingSaves = $count;
    }

    public function nextIdentity(): OfferId
    {
        return OfferId::fromInt(++$this->lastId);
    }

    public function get(OfferId $id): Offer
    {
        return isset($this->stored[$id->value()]) ? $this->copy($this->stored[$id->value()]) : throw new OfferNotFound;
    }

    public function save(Offer $offer): void
    {
        if ($this->failingSaves > 0) {
            $this->failingSaves--;

            throw new ConcurrencyConflict;
        }

        $current = $this->stored[$offer->id()->value()] ?? null;

        if (($current?->version() ?? 0) !== $offer->version()) {
            throw new ConcurrencyConflict;
        }

        // Jak w SQL: los rezerwacji wynika ze zdarzeń, bo agregat nie pamięta
        // zakończonych.
        foreach ($offer->pendingEvents() as $event) {
            $status = match (true) {
                $event instanceof OfferStockReserved => ReservationStatus::Active,
                $event instanceof OfferReservationReleased => ReservationStatus::Released,
                $event instanceof OfferReservationConfirmed => ReservationStatus::Confirmed,
                default => null,
            };

            if ($status !== null) {
                $this->reservationStatuses[$offer->id()->value().':'.$event->key->value()] = $status;
            }
        }

        $this->stored[$offer->id()->value()] = $this->copy($offer, $offer->version() + 1);
    }

    public function reservationStatus(OfferId $offerId, ReservationKey $key): ?ReservationStatus
    {
        return $this->reservationStatuses[$offerId->value().':'.$key->value()] ?? null;
    }

    public function hasLiveOffer(SellerId $sellerId, ProductId $productId): bool
    {
        foreach ($this->stored as $offer) {
            if ($offer->sellerId()->equals($sellerId) && $offer->productId()->equals($productId) && $offer->status() !== OfferStatus::Withdrawn) {
                return true;
            }
        }

        return false;
    }

    public function idsForSellerPolicy(SellerId $sellerId, OfferStatus $status, ?SuspensionKind $suspensionKind, int $afterId, int $limit): array
    {
        $ids = [];

        foreach ($this->stored as $offer) {
            if ($offer->sellerId()->equals($sellerId)
                && $offer->status() === $status
                && ($suspensionKind === null || $offer->suspension()?->kind() === $suspensionKind)
                && $offer->id()->value() > $afterId) {
                $ids[] = $offer->id()->value();
            }
        }

        sort($ids);

        return array_map(OfferId::fromInt(...), array_slice($ids, 0, $limit));
    }

    private function copy(Offer $offer, ?int $version = null): Offer
    {
        return Offer::reconstitute(
            $offer->id(),
            $offer->sellerId(),
            $offer->productId(),
            $offer->price(),
            $offer->stock(),
            $offer->condition(),
            $offer->shippingTime(),
            $offer->status(),
            $offer->suspension(),
            $offer->pendingPriceChange(),
            $offer->recentPriceChanges(),
            $offer->reservations(),
            $version ?? $offer->version(),
        );
    }
}
