<?php

namespace SellerPortal\Application\Offer;

use SellerPortal\Domain\Offer\Actor;
use SellerPortal\Domain\Offer\Offer;
use SellerPortal\Domain\Offer\OfferAction;
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
 * ofertę, ale nic poza tym. Tu też żyje lista akcji dla moderatora
 * (`moderatorActions`), bo ma ten sam problem: agregat oferty nie widzi
 * stanu sprzedawcy.
 */
final readonly class SellerOfferAccess
{
    /** Akcje, których handlery używają `forUser` (bez wymogu aktywnego sprzedawcy). */
    private const array ACTIONS_WITHOUT_ACTIVE_SELLER = [OfferAction::Withdraw];

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

    /**
     * Akcje, które UI może zaproponować sprzedawcy przy tej ofercie.
     *
     * DLACZEGO tutaj, a nie w kontrolerze: to druga strona podziału
     * `forUser` / `forActiveSeller`. Sprzedawca, który nie może sprzedawać
     * (zablokowany), dostaje wyłącznie akcje, których handlery wołają
     * `forUser` — dziś tylko wycofanie (`WithdrawOfferHandler`). Handler
     * przechodzący na `forUser` musi trafić do `ACTIONS_WITHOUT_ACTIVE_SELLER`,
     * inaczej UI ukryje akcję, którą aplikacja by przepuściła.
     *
     * @return list<OfferAction>
     */
    public function sellerActions(Seller $seller, Offer $offer): array
    {
        $actions = $offer->allowedActionsFor(Actor::Seller);

        if ($seller->canSell()) {
            return $actions;
        }

        return array_values(array_filter(
            $actions,
            static fn (OfferAction $action): bool => in_array($action, self::ACTIONS_WITHOUT_ACTIVE_SELLER, true),
        ));
    }

    /**
     * Akcje, które UI może zaproponować moderatorowi przy tej ofercie.
     *
     * DLACZEGO tutaj, obok `sellerActions`: `Offer::allowedActionsFor`
     * widzi tylko stan oferty, a „Przywróć” zależy też od sprzedawcy —
     * `Offer::reinstate(Seller)` odmówi, gdy konto nie może sprzedawać.
     * Bez tego filtra panel pokazywałby przycisk, który zawsze kończy się
     * błędem. Pozostałe akcje moderatora (zawieszenie, decyzja o cenie) nie
     * wystawiają oferty kupującym, więc stan sprzedawcy ich nie dotyczy.
     *
     * @return list<OfferAction>
     */
    public function moderatorActions(Seller $seller, Offer $offer): array
    {
        $actions = $offer->allowedActionsFor(Actor::Moderator);

        if ($seller->canSell()) {
            return $actions;
        }

        return array_values(array_filter(
            $actions,
            static fn (OfferAction $action): bool => $action !== OfferAction::Reinstate,
        ));
    }
}
