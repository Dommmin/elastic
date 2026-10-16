<?php

namespace Tests\Unit\SellerPortal\InMemory;

use SellerPortal\Domain\Offer\Offer;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Offer\OfferNotFound;
use SellerPortal\Domain\Offer\OfferRepository;
use SellerPortal\Domain\Offer\OfferStatus;
use SellerPortal\Domain\Offer\ProductId;
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
        $current = $this->stored[$offer->id()->value()] ?? null;

        if (($current?->version() ?? 0) !== $offer->version()) {
            throw new ConcurrencyConflict;
        }

        $this->stored[$offer->id()->value()] = $this->copy($offer, $offer->version() + 1);
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
