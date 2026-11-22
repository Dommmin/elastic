<?php

use Billing\Domain\BillingAccount;
use Billing\Domain\Event\InvoiceIssued;
use Billing\Domain\Event\InvoiceOverdue;
use Billing\Domain\Event\PaymentReceived;
use Billing\Domain\InvoiceAlreadyPaid;
use Billing\Domain\InvoiceId;
use Billing\Domain\InvoiceNotFound;
use SharedKernel\InvalidValue;

const BILLING_NOW = '2026-11-21 12:00:00';

function billingAccountWithInvoice(string $dueAt = '2026-11-20 12:00:00', int $cents = 10000, string $id = 'F1'): BillingAccount
{
    $account = BillingAccount::open(7);
    $account->issueInvoice(InvoiceId::fromString($id), $cents, new DateTimeImmutable('2026-11-01 12:00:00'), new DateTimeImmutable($dueAt));

    return $account;
}

test('nowe konto ma pusty snapshot i wersję 0', function () {
    $account = BillingAccount::open(7);

    expect($account->snapshot())->toBe(['balance_cents' => 0, 'overdue_cents' => 0, 'overdue_since' => null, 'open_invoices' => 0])
        ->and($account->version())->toBe(0)
        ->and($account->pendingEvents())->toBe([]);
});

test('wystawienie faktury zwiększa saldo i emituje InvoiceIssued', function () {
    $account = billingAccountWithInvoice();

    expect($account->snapshot())->toBe(['balance_cents' => 10000, 'overdue_cents' => 0, 'overdue_since' => null, 'open_invoices' => 1])
        ->and($account->pendingEvents())->toHaveCount(1)
        ->and($account->pendingEvents()[0])->toBeInstanceOf(InvoiceIssued::class);
});

test('faktura 100 zł po terminie po markOverdue daje zaległość od terminu', function () {
    $account = billingAccountWithInvoice('2026-11-20 12:00:00');
    $account->markOverdue(new DateTimeImmutable(BILLING_NOW));

    $snapshot = $account->snapshot();

    expect($snapshot['overdue_cents'])->toBe(10000)
        ->and($snapshot['balance_cents'])->toBe(10000)
        ->and($snapshot['overdue_since'])->toBe((new DateTimeImmutable('2026-11-20 12:00:00'))->format(DATE_ATOM))
        ->and($account->pendingEvents()[1])->toBeInstanceOf(InvoiceOverdue::class);
});

test('płatność zeruje saldo i zaległość', function () {
    $account = billingAccountWithInvoice();
    $account->markOverdue(new DateTimeImmutable(BILLING_NOW));
    $account->recordPayment(InvoiceId::fromString('F1'), new DateTimeImmutable(BILLING_NOW));

    expect($account->snapshot())->toBe(['balance_cents' => 0, 'overdue_cents' => 0, 'overdue_since' => null, 'open_invoices' => 0])
        ->and($account->pendingEvents()[2])->toBeInstanceOf(PaymentReceived::class);
});

test('powtórne markOverdue nie emituje drugiego zdarzenia', function () {
    $account = billingAccountWithInvoice();
    $account->markOverdue(new DateTimeImmutable(BILLING_NOW));
    $account->markOverdue(new DateTimeImmutable(BILLING_NOW));

    $overdue = array_filter($account->pendingEvents(), fn ($e) => $e instanceof InvoiceOverdue);

    expect($overdue)->toHaveCount(1);
});

test('faktura przed terminem nie przechodzi w zaległość', function () {
    $account = billingAccountWithInvoice('2026-11-22 12:00:00');
    $account->markOverdue(new DateTimeImmutable(BILLING_NOW));

    expect($account->snapshot()['overdue_cents'])->toBe(0)
        ->and($account->pendingEvents())->toHaveCount(1);
});

test('overdue_since to najwcześniejszy termin spośród zaległych', function () {
    $account = billingAccountWithInvoice('2026-11-20 12:00:00', 10000, 'F1');
    $account->issueInvoice(InvoiceId::fromString('F2'), 5000, new DateTimeImmutable('2026-10-01 12:00:00'), new DateTimeImmutable('2026-11-10 12:00:00'));
    $account->markOverdue(new DateTimeImmutable(BILLING_NOW));

    $snapshot = $account->snapshot();

    expect($snapshot['overdue_cents'])->toBe(15000)
        ->and($snapshot['overdue_since'])->toBe((new DateTimeImmutable('2026-11-10 12:00:00'))->format(DATE_ATOM))
        ->and($snapshot['open_invoices'])->toBe(2);
});

test('płatność nieznanej lub opłaconej faktury jest odrzucona', function () {
    $account = billingAccountWithInvoice();

    expect(fn () => $account->recordPayment(InvoiceId::fromString('X'), new DateTimeImmutable(BILLING_NOW)))->toThrow(InvoiceNotFound::class);

    $account->recordPayment(InvoiceId::fromString('F1'), new DateTimeImmutable(BILLING_NOW));

    expect(fn () => $account->recordPayment(InvoiceId::fromString('F1'), new DateTimeImmutable(BILLING_NOW)))->toThrow(InvoiceAlreadyPaid::class);
});

test('niepoprawna faktura jest odrzucona', function () {
    $account = BillingAccount::open(7);
    $issued = new DateTimeImmutable('2026-11-01');

    expect(fn () => $account->issueInvoice(InvoiceId::fromString('A'), 0, $issued, $issued))->toThrow(InvalidValue::class);

    $account->issueInvoice(InvoiceId::fromString('C'), 100, $issued, $issued);

    expect(fn () => $account->issueInvoice(InvoiceId::fromString('C'), 100, $issued, $issued))->toThrow(InvalidValue::class);
});
