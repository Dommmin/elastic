<?php

namespace SellerPortal\Infrastructure\Persistence;

use App\Support\Tracing\TraceId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use SellerPortal\Domain\Seller\Event\SellerApproved;
use SellerPortal\Domain\Seller\Event\SellerBlocked;
use SellerPortal\Domain\Seller\Event\SellerRegistered;
use SellerPortal\Domain\Seller\Event\SellerRejected;
use SellerPortal\Domain\Seller\Event\SellerRelocated;
use SellerPortal\Domain\Seller\Event\SellerRenamed;
use SellerPortal\Domain\Seller\Event\SellerUnblocked;
use SellerPortal\Domain\Seller\Seller;
use SharedKernel\DomainEvent;

/**
 * Translacja zdarzeń domenowych sprzedawcy na zdarzenia integracyjne `seller.*`
 * (wiersze outboxu), z których search buduje projekcję `seller-360`.
 *
 * DLACZEGO jawna mapa klasa → nazwa, jak w `OfferIntegrationEvents`: `event_type`
 * i kształt JSON-a to kontrakt dla innych kontekstów. Nowe zdarzenie domenowe
 * bez wpisu tutaj kończy się wyjątkiem, a nie cichym brakiem zdarzenia.
 *
 * DLACZEGO każdy typ niesie ten sam, pełny ładunek (stan sprzedawcy PO zapisie):
 * konsument nie dopytuje o agregat, a sekcję `profile` zastępuje w całości.
 * `version` = wersja agregatu po zapisie, a zarazem `sequence` koperty; kilka
 * zdarzeń z jednego zapisu dzieli ją, bo opisują jeden stan.
 */
final class SellerIntegrationEvents
{
    /**
     * Wołać w tej samej transakcji co zapis stanu sprzedawcy. `$seller` to stan
     * po zmianie; `$version` to wersja zapisana w bazie.
     *
     * @param  list<DomainEvent>  $events
     */
    public static function record(Seller $seller, array $events, int $version): void
    {
        foreach ($events as $event) {
            DB::table('outbox')->insert([
                'event_id' => (string) Str::ulid(),
                'aggregate_type' => 'seller',
                'aggregate_id' => (string) $seller->id()->value(),
                'event_type' => self::eventType($event),
                'payload' => json_encode([
                    'seller_id' => $seller->id()->value(),
                    'name' => $seller->name()->value(),
                    'city' => $seller->city()->value(),
                    'nip' => $seller->taxId()->value(),
                    'status' => $seller->status()->value,
                    'status_reason' => $seller->statusReason()?->value(),
                    'synthetic' => $seller->isSynthetic(),
                    'version' => $version,
                    'occurred_at' => SqlTimestamp::format($event->occurredAt()),
                ], JSON_THROW_ON_ERROR),
                'sequence' => $version,
                'occurred_at' => SqlTimestamp::format($event->occurredAt()),
                'trace_id' => TraceId::current(),
            ]);
        }
    }

    private static function eventType(DomainEvent $event): string
    {
        return match (true) {
            $event instanceof SellerRegistered => 'seller.registered',
            $event instanceof SellerApproved => 'seller.approved',
            $event instanceof SellerRejected => 'seller.rejected',
            $event instanceof SellerBlocked => 'seller.blocked',
            $event instanceof SellerUnblocked => 'seller.unblocked',
            $event instanceof SellerRenamed => 'seller.renamed',
            $event instanceof SellerRelocated => 'seller.relocated',
            default => throw new LogicException('Brak mapowania na zdarzenie integracyjne dla '.$event::class.'.'),
        };
    }
}
