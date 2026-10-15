<?php

namespace SellerPortal\Application\Offer;

use SellerPortal\Application\ExpectedVersion;
use SellerPortal\Application\Port\Clock;
use SellerPortal\Application\Port\DomainEventDispatcher;
use SellerPortal\Application\Port\TransactionManager;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Offer\OfferRepository;
use SellerPortal\Domain\Shared\UserId;

/**
 * Handler komendy WithdrawOffer: wczytaj → zmień agregat → zapisz → roześlij zdarzenia, wszystko w jednej transakcji.
 * DLACZEGO zdarzenia lecą wewnątrz transakcji: słuchacze (np. kolejka polityk) wpisują skutki atomowo ze stanem, więc nie ma okna, w którym stan jest zapisany, a reakcja zgubiona. Reguły biznesowe żyją w agregacie, nie tutaj.
 */
final readonly class WithdrawOfferHandler
{
    public function __construct(
        private TransactionManager $transactions,
        private Clock $clock,
        private DomainEventDispatcher $events,
        private SellerOfferAccess $access,
        private OfferRepository $offers,
    ) {}

    public function handle(WithdrawOffer $command): void
    {
        $this->transactions->run(function () use ($command): void {
            [, $offer] = $this->access->forUser(UserId::fromInt($command->userId), OfferId::fromInt($command->offerId));
            ExpectedVersion::check($offer, $command->expectedVersion);
            $offer->withdraw($this->clock->now());
            $this->offers->save($offer);
            $this->events->dispatch($offer->releaseEvents());
        });
    }
}
