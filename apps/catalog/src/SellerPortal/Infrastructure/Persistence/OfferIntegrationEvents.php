<?php

namespace SellerPortal\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use SellerPortal\Domain\Offer\Event\OfferBackInStock;
use SellerPortal\Domain\Offer\Event\OfferDetailsChanged;
use SellerPortal\Domain\Offer\Event\OfferDrafted;
use SellerPortal\Domain\Offer\Event\OfferPriceChangeApproved;
use SellerPortal\Domain\Offer\Event\OfferPriceChangeCancelled;
use SellerPortal\Domain\Offer\Event\OfferPriceChanged;
use SellerPortal\Domain\Offer\Event\OfferPriceChangeRejected;
use SellerPortal\Domain\Offer\Event\OfferPriceChangeRequested;
use SellerPortal\Domain\Offer\Event\OfferPublished;
use SellerPortal\Domain\Offer\Event\OfferReinstated;
use SellerPortal\Domain\Offer\Event\OfferSoldOut;
use SellerPortal\Domain\Offer\Event\OfferStockChanged;
use SellerPortal\Domain\Offer\Event\OfferSuspended;
use SellerPortal\Domain\Offer\Event\OfferWithdrawn;
use SellerPortal\Domain\Offer\Offer;
use SharedKernel\DomainEvent;

/**
 * Translacja zdarzeń domenowych oferty na zdarzenia integracyjne (wiersze outboxu).
 *
 * DLACZEGO jawna mapa klasa → nazwa, a nie serializacja klas domenowych:
 * `event_type` i kształt JSON-a to publiczny kontrakt dla innych kontekstów
 * (search). Klasę domenową wolno przemianować; nazwy `offer.*` nie, bo łamią
 * konsumentów. Nowe zdarzenie domenowe bez wpisu tutaj kończy się wyjątkiem,
 * a nie cichym brakiem zdarzenia.
 *
 * DLACZEGO payload to stan oferty PO zapisie plus pola zdarzenia: konsument
 * (ProductSyncHandler) potrzebuje `product_id` i aktualnych `status`/`listed`,
 * a nie ma jak dopytać o agregat. `sequence` = nowa wersja oferty; kilka
 * zdarzeń z jednego zapisu dzieli ten sam `sequence`, bo to jeden stan.
 *
 * Zdarzenia sprzedawcy celowo tu nie trafiają: nie każde zdarzenie
 * domenowe zasługuje na kontrakt publiczny.
 */
final class OfferIntegrationEvents
{
    /**
     * Wołać w tej samej transakcji co zapis stanu oferty. `$offer` to stan po
     * zmianie; `$sequence` to wersja zapisana w bazie.
     *
     * @param  list<DomainEvent>  $events
     */
    public static function record(Offer $offer, array $events, int $sequence): void
    {
        foreach ($events as $event) {
            [$eventType, $specific] = self::translate($event);

            DB::table('outbox')->insert([
                'event_id' => (string) Str::ulid(),
                'aggregate_type' => 'offer',
                'aggregate_id' => (string) $offer->id()->value(),
                'event_type' => $eventType,
                'payload' => json_encode([...self::base($offer, $sequence), ...$specific], JSON_THROW_ON_ERROR),
                'sequence' => $sequence,
                'occurred_at' => SqlTimestamp::format($event->occurredAt()),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function base(Offer $offer, int $version): array
    {
        return [
            'id' => $offer->id()->value(),
            'product_id' => $offer->productId()->value(),
            'seller_id' => $offer->sellerId()->value(),
            'price_cents' => $offer->price()->cents(),
            'currency' => $offer->price()->currency()->value,
            'stock' => $offer->stock()->value(),
            'condition' => $offer->condition()->value,
            'shipping_days' => $offer->shippingTime()->value(),
            'status' => $offer->status()->value,
            'listed' => $offer->isListed(),
            'version' => $version,
        ];
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function translate(DomainEvent $event): array
    {
        return match (true) {
            $event instanceof OfferDrafted => ['offer.drafted', []],
            $event instanceof OfferPublished => ['offer.published', []],
            $event instanceof OfferPriceChanged => ['offer.price_changed', [
                'old_price_cents' => $event->old->cents(),
                'new_price_cents' => $event->new->cents(),
            ]],
            $event instanceof OfferPriceChangeRequested => ['offer.price_change_requested', [
                'proposed_price_cents' => $event->proposed->cents(),
            ]],
            $event instanceof OfferPriceChangeApproved => ['offer.price_change_approved', [
                'old_price_cents' => $event->old->cents(),
                'new_price_cents' => $event->new->cents(),
            ]],
            $event instanceof OfferPriceChangeRejected => ['offer.price_change_rejected', [
                'proposed_price_cents' => $event->proposed->cents(),
                'reason' => $event->reason->value(),
            ]],
            $event instanceof OfferPriceChangeCancelled => ['offer.price_change_cancelled', [
                'proposed_price_cents' => $event->proposed->cents(),
            ]],
            $event instanceof OfferStockChanged => ['offer.stock_changed', [
                'old_stock' => $event->old->value(),
                'new_stock' => $event->new->value(),
            ]],
            $event instanceof OfferSoldOut => ['offer.sold_out', []],
            $event instanceof OfferBackInStock => ['offer.back_in_stock', []],
            $event instanceof OfferDetailsChanged => ['offer.details_changed', []],
            $event instanceof OfferSuspended => ['offer.suspended', [
                'suspension_kind' => $event->reason->kind()->value,
                'suspension_note' => $event->reason->note()?->value(),
            ]],
            $event instanceof OfferReinstated => ['offer.reinstated', []],
            $event instanceof OfferWithdrawn => ['offer.withdrawn', []],
            default => throw new LogicException('Brak mapowania na zdarzenie integracyjne dla '.$event::class.'.'),
        };
    }
}
