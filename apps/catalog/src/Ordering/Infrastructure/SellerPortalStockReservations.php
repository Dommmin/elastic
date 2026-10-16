<?php

namespace Ordering\Infrastructure;

use Ordering\Application\Port\ReservationResult;
use Ordering\Application\Port\StockReservations;
use Ordering\Domain\LineQuantity;
use Ordering\Domain\OfferId;
use SellerPortal\Application\Reservation\ConfirmReservation;
use SellerPortal\Application\Reservation\ConfirmReservationHandler;
use SellerPortal\Application\Reservation\ReleaseReservation;
use SellerPortal\Application\Reservation\ReleaseReservationHandler;
use SellerPortal\Application\Reservation\ReserveStock;
use SellerPortal\Application\Reservation\ReserveStockHandler;
use SellerPortal\Domain\Offer\InsufficientStock;
use SellerPortal\Domain\Offer\OfferNotFound;
use SellerPortal\Domain\Offer\OfferNotReservable;
use SellerPortal\Domain\Offer\ReservationPriceMismatch;
use SellerPortal\Domain\Seller\SellerCannotSell;
use SharedKernel\Money;

/**
 * Warstwa antykorupcyjna: Ordering mówi swoim językiem (`ReservationResult`),
 * portal swoimi komendami i wyjątkami domeny.
 *
 * DLACZEGO tłumaczymy tylko odmowy biznesowe: brak towaru, zmiana ceny
 * i niedostępność oferty (nieopublikowana, sprzedawca zablokowany, usunięta)
 * to zwykłe wyniki, po których zamówienie jest `Rejected`. Cała reszta,
 * m.in. `ReservationConflict` (klucz już zwolniony albo potwierdzony), to
 * błąd programisty albo awaria i musi polecieć dalej: job ponowi i trafi do
 * `failed_jobs`, zamiast po cichu odrzucić zamówienie.
 */
final readonly class SellerPortalStockReservations implements StockReservations
{
    public function __construct(
        private ReserveStockHandler $reserve,
        private ReleaseReservationHandler $release,
        private ConfirmReservationHandler $confirm,
    ) {}

    public function reserve(string $key, OfferId $offerId, LineQuantity $quantity, Money $unitPrice): ReservationResult
    {
        try {
            $this->reserve->handle(new ReserveStock(
                $key,
                $offerId->value(),
                $quantity->value(),
                $unitPrice->cents(),
                $unitPrice->currency()->value,
            ));
        } catch (InsufficientStock) {
            return ReservationResult::OutOfStock;
        } catch (ReservationPriceMismatch) {
            return ReservationResult::PriceChanged;
        } catch (OfferNotReservable|SellerCannotSell|OfferNotFound) {
            return ReservationResult::OfferUnavailable;
        }

        return ReservationResult::Reserved;
    }

    public function release(string $key, OfferId $offerId): void
    {
        $this->release->handle(new ReleaseReservation($key, $offerId->value()));
    }

    public function confirm(string $key, OfferId $offerId): void
    {
        $this->confirm->handle(new ConfirmReservation($key, $offerId->value()));
    }
}
