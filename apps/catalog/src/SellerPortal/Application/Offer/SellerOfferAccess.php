<?php

namespace SellerPortal\Application\Offer;

use SellerPortal\Domain\Offer\Offer;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Offer\OfferRepository;
use SellerPortal\Domain\Seller\Seller;
use SellerPortal\Domain\Seller\SellerCannotSell;
use SellerPortal\Domain\Seller\SellerNotFound;
use SellerPortal\Domain\Seller\SellerRepository;
use SellerPortal\Domain\Shared\UserId;

/**
 * Kto może dotknąć której oferty: wspólny krok handlerów sprzedawcy.
 *
 * DLACZEGO "aktywny sprzedawca" siedzi tutaj, a nie w każdej metodzie
 * `Offer`: to reguła dostępu AKTORA, nie stanu oferty. Wyjątek to
 * `Offer::publish(Seller)`, bo spec czyni ją warunkiem publikacji.
 * DLACZEGO `forUser` istnieje obok: zablokowany sprzedawca ma móc wycofać
 * ofertę, ale nic poza tym.
 */
final readonly class SellerOfferAccess
{
    public function __construct(
        private SellerRepository $sellers,
        private OfferRepository $offers,
    ) {}

    /**
     * @return array{0: Seller, 1: Offer}
     *
     * @throws SellerNotFound
     * @throws OfferNotOwned
     */
    public function forUser(UserId $userId, OfferId $offerId): array
    {
        $seller = $this->sellers->findByUserId($userId) ?? throw new SellerNotFound;
        $offer = $this->offers->get($offerId);

        if (! $offer->sellerId()->equals($seller->id())) {
            throw new OfferNotOwned;
        }

        return [$seller, $offer];
    }

    /**
     * @return array{0: Seller, 1: Offer}
     *
     * @throws SellerNotFound
     * @throws OfferNotOwned
     * @throws SellerCannotSell
     */
    public function forActiveSeller(UserId $userId, OfferId $offerId): array
    {
        [$seller, $offer] = $this->forUser($userId, $offerId);

        if (! $seller->canSell()) {
            throw new SellerCannotSell;
        }

        return [$seller, $offer];
    }
}
