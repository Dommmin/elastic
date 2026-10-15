<?php

namespace SellerPortal\Application\Moderation;

use SellerPortal\Application\ExpectedVersion;
use SellerPortal\Application\Port\Clock;
use SellerPortal\Application\Port\DomainEventDispatcher;
use SellerPortal\Application\Port\TransactionManager;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Offer\OfferRepository;
use SellerPortal\Domain\Seller\SellerRepository;

/**
 * Handler komendy ReinstateOffer: wczytaj → zmień agregat → zapisz → roześlij zdarzenia, wszystko w jednej transakcji.
 * DLACZEGO handler wczytuje sprzedawcę: przywrócenie wystawia ofertę kupującym, więc agregat sprawdza `Seller::canSell` (zablokowany sprzedawca nie wraca do wyszukiwarki przez kliknięcie moderatora).
 * DLACZEGO zdarzenia lecą wewnątrz transakcji: słuchacze (np. kolejka polityk) wpisują skutki atomowo ze stanem, więc nie ma okna, w którym stan jest zapisany, a reakcja zgubiona. Reguły biznesowe żyją w agregacie, nie tutaj.
 */
final readonly class ReinstateOfferHandler
{
    public function __construct(
        private TransactionManager $transactions,
        private Clock $clock,
        private DomainEventDispatcher $events,
        private OfferRepository $offers,
        private SellerRepository $sellers,
    ) {}

    public function handle(ReinstateOffer $command): void
    {
        $this->transactions->run(function () use ($command): void {
            $offer = $this->offers->get(OfferId::fromInt($command->offerId));
            ExpectedVersion::check($offer, $command->expectedVersion);
            $offer->reinstate($this->sellers->get($offer->sellerId()), $this->clock->now());
            $this->offers->save($offer);
            $this->events->dispatch($offer->releaseEvents());
        });
    }
}
