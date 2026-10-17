<?php

namespace SellerPortal\Application\Reservation;

use SellerPortal\Application\Port\Clock;
use SellerPortal\Application\Port\DomainEventDispatcher;
use SellerPortal\Application\Port\TransactionManager;
use SellerPortal\Application\RetryOnConflict;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Offer\OfferRepository;
use SellerPortal\Domain\Offer\Quantity;
use SellerPortal\Domain\Offer\ReservationConflict;
use SellerPortal\Domain\Offer\ReservationKey;
use SellerPortal\Domain\Offer\ReservationStatus;
use SellerPortal\Domain\Seller\SellerRepository;
use SharedKernel\Currency;
use SharedKernel\Money;

/**
 * Handler komendy ReserveStock: wczytaj ofertę i jej sprzedawcę → zarezerwuj → zapisz → roześlij zdarzenia.
 * DLACZEGO sprzedawcę wczytujemy tu, a nie bierzemy od wołającego: to portal decyduje, czy konto może sprzedawać (H11); kontekst Ordering nie zna sprzedawców.
 * DLACZEGO sprawdzamy `reservationStatus` przed agregatem (R12): agregat zna tylko aktywne rezerwacje, więc dla klucza już zwolnionego lub potwierdzonego próbowałby wstawić wiersz i trafiłby na indeks unikalny (surowy QueryException zamiast wyjątku domeny).
 */
final readonly class ReserveStockHandler
{
    public function __construct(
        private TransactionManager $transactions,
        private Clock $clock,
        private DomainEventDispatcher $events,
        private OfferRepository $offers,
        private SellerRepository $sellers,
    ) {}

    public function handle(ReserveStock $command): void
    {
        $offerId = OfferId::fromInt($command->offerId);
        $key = ReservationKey::fromString($command->reservationKey);
        $quantity = Quantity::fromInt($command->quantity);
        $price = Money::fromCents($command->expectedUnitPriceCents, Currency::from($command->currency));

        RetryOnConflict::run(fn () => $this->transactions->run(function () use ($offerId, $key, $quantity, $price): void {
            $status = $this->offers->reservationStatus($offerId, $key);

            if ($status === ReservationStatus::Released || $status === ReservationStatus::Confirmed) {
                throw new ReservationConflict;
            }

            $offer = $this->offers->get($offerId);
            $seller = $this->sellers->get($offer->sellerId());
            $offer->reserve($seller, $key, $quantity, $price, $this->clock->now());
            $this->offers->save($offer);
            $this->events->dispatch($offer->releaseEvents());
        }));
    }
}
