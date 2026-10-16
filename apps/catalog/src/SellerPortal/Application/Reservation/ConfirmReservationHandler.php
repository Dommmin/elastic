<?php

namespace SellerPortal\Application\Reservation;

use SellerPortal\Application\Port\Clock;
use SellerPortal\Application\Port\DomainEventDispatcher;
use SellerPortal\Application\Port\TransactionManager;
use SellerPortal\Application\RetryOnConflict;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Offer\OfferRepository;
use SellerPortal\Domain\Offer\ReservationKey;
use SellerPortal\Domain\Offer\ReservationStatus;

/**
 * Handler komendy ConfirmReservation.
 * DLACZEGO idempotencja tutaj: job potwierdzenia bywa ponawiany po awarii, a agregat nie pamięta potwierdzonych. Klucz już `confirmed` kończy się cicho; zwolniony albo nieznany dalej rzuca `ReservationNotFound` (sprzedaż bez zmniejszenia stanu byłaby gorsza niż błąd).
 */
final readonly class ConfirmReservationHandler
{
    public function __construct(
        private TransactionManager $transactions,
        private Clock $clock,
        private DomainEventDispatcher $events,
        private OfferRepository $offers,
    ) {}

    public function handle(ConfirmReservation $command): void
    {
        $offerId = OfferId::fromInt($command->offerId);
        $key = ReservationKey::fromString($command->reservationKey);

        RetryOnConflict::run(fn () => $this->transactions->run(function () use ($offerId, $key): void {
            $offer = $this->offers->get($offerId);

            if ($this->offers->reservationStatus($offerId, $key) === ReservationStatus::Confirmed) {
                return;
            }

            $offer->confirmReservation($key, $this->clock->now());
            $this->offers->save($offer);
            $this->events->dispatch($offer->releaseEvents());
        }));
    }
}
