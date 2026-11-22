<?php

namespace Billing\Domain;

use Billing\Domain\Event\InvoiceIssued;
use Billing\Domain\Event\InvoiceOverdue;
use Billing\Domain\Event\PaymentReceived;
use DateTimeImmutable;
use SharedKernel\AggregateRoot;
use SharedKernel\InvalidValue;

/**
 * Agregat konta rozliczeniowego sprzedawcy: jego faktury i zaległości.
 *
 * DLACZEGO osobny kontekst, a nie kolumny w `sellers`: rozliczenia mają własny
 * cykl życia i własne zdarzenia (`billing.*`); projekcja seller-360 składa je
 * z profilem dopiero po stronie search. Konto odwołuje się do sprzedawcy
 * tylko liczbą (`sellerId`), bez zależności od kontekstu SellerPortal.
 *
 * DLACZEGO `markOverdue` przyjmuje `$now`: domena nie zna zegara. Zdarzenie
 * leci raz na fakturę (status Open -> Overdue), więc cykliczny sweep jest
 * idempotentny.
 */
final class BillingAccount extends AggregateRoot
{
    /** @var array<string, Invoice> */
    private array $invoices = [];

    /**
     * @param  list<Invoice>  $invoices
     */
    private function __construct(
        private readonly int $sellerId,
        array $invoices,
        private readonly int $version,
    ) {
        foreach ($invoices as $invoice) {
            $this->invoices[$invoice->id->value()] = $invoice;
        }
    }

    public static function open(int $sellerId): self
    {
        if ($sellerId <= 0) {
            throw new InvalidValue('Identyfikator sprzedawcy musi być dodatni.');
        }

        return new self($sellerId, [], 0);
    }

    /**
     * @param  list<Invoice>  $invoices
     */
    public static function reconstitute(int $sellerId, array $invoices, int $version): self
    {
        return new self($sellerId, $invoices, $version);
    }

    public function sellerId(): int
    {
        return $this->sellerId;
    }

    public function version(): int
    {
        return $this->version;
    }

    /**
     * @return list<Invoice>
     */
    public function invoices(): array
    {
        return array_values($this->invoices);
    }

    public function issueInvoice(InvoiceId $id, int $amountCents, DateTimeImmutable $issuedAt, DateTimeImmutable $dueAt): void
    {
        if (isset($this->invoices[$id->value()])) {
            throw new InvalidValue('Faktura o tym identyfikatorze już istnieje.');
        }

        $this->invoices[$id->value()] = new Invoice($id, $amountCents, $issuedAt, $dueAt);
        $this->recordThat(new InvoiceIssued($this->sellerId, $id, $amountCents, $issuedAt));
    }

    public function recordPayment(InvoiceId $id, DateTimeImmutable $paidAt): void
    {
        $invoice = $this->invoices[$id->value()] ?? throw new InvoiceNotFound;

        if (! $invoice->isUnpaid()) {
            throw new InvoiceAlreadyPaid;
        }

        $invoice->pay($paidAt);
        $this->recordThat(new PaymentReceived($this->sellerId, $id, $invoice->amountCents, $paidAt));
    }

    public function markOverdue(DateTimeImmutable $now): void
    {
        foreach ($this->invoices as $invoice) {
            if ($invoice->isDueBefore($now)) {
                $invoice->markOverdue();
                $this->recordThat(new InvoiceOverdue($this->sellerId, $invoice->id, $invoice->amountCents, $now));
            }
        }
    }

    /**
     * Stan rozliczeń po ostatniej zmianie: wkład konta do sekcji `billing`.
     *
     * @return array{balance_cents:int, overdue_cents:int, overdue_since:?string, open_invoices:int}
     */
    public function snapshot(): array
    {
        $balance = 0;
        $overdue = 0;
        $open = 0;
        $since = null;

        foreach ($this->invoices as $invoice) {
            if (! $invoice->isUnpaid()) {
                continue;
            }

            $open++;
            $balance += $invoice->amountCents;

            if ($invoice->isOverdue()) {
                $overdue += $invoice->amountCents;
                $since = $since === null || $invoice->dueAt < $since ? $invoice->dueAt : $since;
            }
        }

        return [
            'balance_cents' => $balance,
            'overdue_cents' => $overdue,
            'overdue_since' => $since?->format(DATE_ATOM),
            'open_invoices' => $open,
        ];
    }
}
