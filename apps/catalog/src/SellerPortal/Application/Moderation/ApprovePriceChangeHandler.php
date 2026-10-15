<?php

namespace SellerPortal\Application\Moderation;

use SellerPortal\Application\Port\Clock;
use SellerPortal\Application\Port\DomainEventDispatcher;
use SellerPortal\Application\Port\TransactionManager;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Offer\OfferRepository;
use SellerPortal\Domain\Shared\ModeratorId;

/**
 * Handler komendy ApprovePriceChange: wczytaj → zmień agregat → zapisz → roześlij zdarzenia, wszystko w jednej transakcji.
 * DLACZEGO zdarzenia lecą wewnątrz transakcji: słuchacze (np. kolejka polityk) wpisują skutki atomowo ze stanem, więc nie ma okna, w którym stan jest zapisany, a reakcja zgubiona. Reguły biznesowe żyją w agregacie, nie tutaj.
 */
final readonly class ApprovePriceChangeHandler
{
    public function __construct(
        private TransactionManager $transactions,
        private Clock $clock,
        private DomainEventDispatcher $events,
        private OfferRepository $offers,
    ) {}

    public function handle(ApprovePriceChange $command): void
    {
        $this->transactions->run(function () use ($command): void {
            $offer = $this->offers->get(OfferId::fromInt($command->offerId));
            $offer->approvePriceChange(ModeratorId::fromInt($command->moderatorId), $this->clock->now());
            $this->offers->save($offer);
            $this->events->dispatch($offer->releaseEvents());
        });
    }
}
