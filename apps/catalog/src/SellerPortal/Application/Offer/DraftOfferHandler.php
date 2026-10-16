<?php

namespace SellerPortal\Application\Offer;

use SellerPortal\Application\Port\Clock;
use SellerPortal\Application\Port\DomainEventDispatcher;
use SellerPortal\Application\Port\ProductCatalog;
use SellerPortal\Application\Port\TransactionManager;
use SellerPortal\Application\ProductNotFound;
use SellerPortal\Domain\Offer\Condition;
use SellerPortal\Domain\Offer\LiveOfferAlreadyExists;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Offer\OfferRepository;
use SellerPortal\Domain\Offer\ProductId;
use SellerPortal\Domain\Offer\ShippingTime;
use SellerPortal\Domain\Offer\Stock;
use SellerPortal\Domain\Seller\SellerNotFound;
use SellerPortal\Domain\Seller\SellerRepository;
use SellerPortal\Domain\Shared\UserId;
use SharedKernel\InvalidValue;
use SharedKernel\Money;

/**
 * Handler komendy DraftOffer: wczytaj → zmień agregat → zapisz → roześlij zdarzenia, wszystko w jednej transakcji.
 * DLACZEGO zdarzenia lecą wewnątrz transakcji: słuchacze (np. kolejka polityk) wpisują skutki atomowo ze stanem, więc nie ma okna, w którym stan jest zapisany, a reakcja zgubiona. Reguły biznesowe żyją w agregacie, nie tutaj.
 */
final readonly class DraftOfferHandler
{
    public function __construct(
        private TransactionManager $transactions,
        private Clock $clock,
        private DomainEventDispatcher $events,
        private SellerRepository $sellers,
        private OfferRepository $offers,
        private ProductCatalog $catalog,
    ) {}

    public function handle(DraftOffer $command): OfferId
    {
        return $this->transactions->run(function () use ($command): OfferId {
            $seller = $this->sellers->findByUserId(UserId::fromInt($command->userId)) ?? throw new SellerNotFound;
            $productId = ProductId::fromInt($command->productId);

            if (! $this->catalog->exists($productId)) {
                throw new ProductNotFound;
            }

            if ($this->offers->hasLiveOffer($seller->id(), $productId)) {
                throw new LiveOfferAlreadyExists;
            }

            $offer = $seller->draftOffer(
                $this->offers->nextIdentity(),
                $productId,
                Money::fromCents($command->priceCents),
                Stock::fromInt($command->stock),
                Condition::tryFrom($command->condition) ?? throw new InvalidValue('Nieznany stan egzemplarza.'),
                ShippingTime::fromInt($command->shippingDays),
                $this->clock->now(),
            );
            $this->offers->save($offer);
            $this->events->dispatch($offer->releaseEvents());

            return $offer->id();
        });
    }
}
