<?php

namespace Billing\Infrastructure;

use App\Support\Tracing\TraceId;
use Billing\Domain\BillingAccount;
use Billing\Domain\Event\InvoiceIssued;
use Billing\Domain\Event\InvoiceOverdue;
use Billing\Domain\Event\PaymentReceived;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use SellerPortal\Infrastructure\Persistence\SqlTimestamp;
use SharedKernel\DomainEvent;

/**
 * Translacja zdarzeń domenowych Billing na zdarzenia integracyjne `billing.*`
 * (wiersze outboxu), z których search buduje sekcję `billing` w `seller-360`.
 *
 * DLACZEGO jawna mapa klasa → nazwa: `event_type` i kształt JSON-a to kontrakt
 * dla innych kontekstów, a nowe zdarzenie bez wpisu ma kończyć się wyjątkiem.
 *
 * DLACZEGO każdy typ niesie pełny snapshot konta PO zapisie: konsument zastępuje
 * sekcję w całości. `version` = wersja agregatu po zapisie i zarazem `sequence`;
 * zdarzenia z jednego zapisu dzielą ją, bo opisują jeden stan.
 */
final class BillingIntegrationEvents
{
    /**
     * Wołać w tej samej transakcji co zapis konta.
     *
     * @param  list<DomainEvent>  $events
     */
    public static function record(BillingAccount $account, array $events, int $version): void
    {
        $snapshot = $account->snapshot();

        foreach ($events as $event) {
            DB::table('outbox')->insert([
                'event_id' => (string) Str::ulid(),
                'aggregate_type' => 'billing_account',
                'aggregate_id' => (string) $account->sellerId(),
                'event_type' => self::eventType($event),
                'payload' => json_encode([
                    'seller_id' => $account->sellerId(),
                    ...$snapshot,
                    'invoice_id' => $event->invoiceId->value(),
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
            $event instanceof InvoiceIssued => 'billing.invoice_issued',
            $event instanceof PaymentReceived => 'billing.payment_received',
            $event instanceof InvoiceOverdue => 'billing.invoice_overdue',
            default => throw new LogicException('Brak mapowania na zdarzenie integracyjne dla '.$event::class.'.'),
        };
    }
}
