<?php

namespace SellerPortal\Application\Reservation;

use SellerPortal\Application\Port\Clock;
use SellerPortal\Application\Port\DomainEventDispatcher;
use SellerPortal\Application\Port\TransactionManager;
use SellerPortal\Application\RetryOnConflict;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Offer\OfferRepository;
use SellerPortal\Domain\Offer\ReservationKey;

/**
 * Handler komendy ReleaseReservation. Brak aktywnej rezerwacji to no-op w agregacie, więc ponowiona kompensacja jest bezpieczna.
 */
final readonly class ReleaseReservationHandler
{
    public function __construct(
        private TransactionManager $transactions,
        private Clock $clock,
        private DomainEventDispatcher $events,
        private OfferRepository $offers,
    ) {}

    public function handle(ReleaseReservation $command): void
    {
        $offerId = OfferId::fromInt($command->offerId);
        $key = ReservationKey::fromString($command->reservationKey);

        RetryOnConflict::run(fn () => $this->transactions->run(function () use ($offerId, $key): void {
            $offer = $this->offers->get($offerId);
            $offer->releaseReservation($key, $this->clock->now());
            $this->offers->save($offer);
            $this->events->dispatch($offer->releaseEvents());
        }));
    }
}
