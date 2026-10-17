<?php

namespace SellerPortal\Application\Moderation;

use SellerPortal\Application\ExpectedVersion;
use SellerPortal\Application\Port\Clock;
use SellerPortal\Application\Port\DomainEventDispatcher;
use SellerPortal\Application\Port\TransactionManager;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Offer\OfferRepository;
use SellerPortal\Domain\Shared\ModeratorId;
use SellerPortal\Domain\Shared\Reason;
use SharedKernel\Money;

/**
 * Handler komendy RejectPriceChange: wczytaj → zmień agregat → zapisz → roześlij zdarzenia, wszystko w jednej transakcji.
 * DLACZEGO wersja sprawdzana PO decyzji agregatu: gdy sprzedawca w międzyczasie zmienił zgłoszoną cenę, zmieniła się też wersja — a moderator powinien dostać konkretny powód („sprzedawca zmienił zgłoszoną cenę”), nie ogólne „ktoś zmienił ofertę”. Nic nie jest jeszcze zapisane, więc kolejność nie wpływa na stan.
 * DLACZEGO zdarzenia lecą wewnątrz transakcji: słuchacze (np. kolejka polityk) wpisują skutki atomowo ze stanem, więc nie ma okna, w którym stan jest zapisany, a reakcja zgubiona. Reguły biznesowe żyją w agregacie, nie tutaj.
 */
final readonly class RejectPriceChangeHandler
{
    public function __construct(
        private TransactionManager $transactions,
        private Clock $clock,
        private DomainEventDispatcher $events,
        private OfferRepository $offers,
    ) {}

    public function handle(RejectPriceChange $command): void
    {
        $this->transactions->run(function () use ($command): void {
            $offer = $this->offers->get(OfferId::fromInt($command->offerId));
            $offer->rejectPriceChange(
                ModeratorId::fromInt($command->moderatorId),
                Money::fromCents($command->expectedPriceCents),
                Reason::fromString($command->reason),
                $this->clock->now(),
            );
            ExpectedVersion::check($offer, $command->expectedVersion);
            $this->offers->save($offer);
            $this->events->dispatch($offer->releaseEvents());
        });
    }
}
