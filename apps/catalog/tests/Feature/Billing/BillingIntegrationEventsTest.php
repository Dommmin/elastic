<?php

use Billing\Application\IssueInvoice;
use Billing\Application\IssueInvoiceHandler;
use Billing\Application\MarkOverdueInvoicesHandler;
use Billing\Application\RecordPayment;
use Billing\Application\RecordPaymentHandler;
use Billing\Domain\BillingAccountRepository;
use Billing\Domain\InvoiceId;
use Billing\Infrastructure\SqlBillingAccountRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SharedKernel\ConcurrencyConflict;

/** @return list<object> */
function billingOutboxRows(int $sellerId): array
{
    return DB::table('outbox')->where('aggregate_type', 'billing_account')->where('aggregate_id', (string) $sellerId)
        ->orderBy('sequence')->orderBy('event_id')->get()->all();
}

beforeEach(function () {
    Carbon::setTestNow('2026-11-21 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

test('wystawienie, płatność i zaległość dają trzy wiersze outboxu z rosnącym version i snapshotem', function () {
    $issue = app(IssueInvoiceHandler::class);
    $seller = 42;

    // F1 po terminie (zaległa), F2 przed terminem (zapłacona).
    $f1 = $issue->handle(new IssueInvoice($seller, 10000, new DateTimeImmutable('2026-11-20 12:00:00')));
    $f2 = $issue->handle(new IssueInvoice($seller, 2500, new DateTimeImmutable('2026-12-20 12:00:00')));

    expect(app(MarkOverdueInvoicesHandler::class)->handle())->toBe(1);

    app(RecordPaymentHandler::class)->handle(new RecordPayment($seller, $f2));

    $rows = billingOutboxRows($seller);
    $types = array_map(fn ($r) => $r->event_type, $rows);

    expect($rows)->toHaveCount(4)
        ->and($types)->toBe(['billing.invoice_issued', 'billing.invoice_issued', 'billing.invoice_overdue', 'billing.payment_received'])
        ->and(array_map(fn ($r) => (int) $r->sequence, $rows))->toBe([1, 2, 3, 4])
        ->and($rows[0]->aggregate_id)->toBe('42')
        ->and($rows[0]->event_id)->toHaveLength(26);

    $first = json_decode($rows[0]->payload, true);
    expect($first)->toMatchArray(['seller_id' => 42, 'balance_cents' => 10000, 'overdue_cents' => 0, 'overdue_since' => null, 'open_invoices' => 1, 'invoice_id' => $f1->value(), 'version' => 1]);

    $overdue = json_decode($rows[2]->payload, true);
    expect($overdue)->toMatchArray(['balance_cents' => 12500, 'overdue_cents' => 10000, 'open_invoices' => 2, 'invoice_id' => $f1->value(), 'version' => 3])
        ->and($overdue['overdue_since'])->toStartWith('2026-11-20');

    expect($first['occurred_at'])->toBe('2026-11-21T12:00:00.000000+00:00');

    $paid = json_decode($rows[3]->payload, true);
    expect($paid)->toMatchArray(['balance_cents' => 10000, 'overdue_cents' => 10000, 'open_invoices' => 1, 'invoice_id' => $f2->value(), 'version' => 4]);
});

test('drugi przebieg sweepu nie dopisuje zdarzeń', function () {
    app(IssueInvoiceHandler::class)->handle(new IssueInvoice(42, 10000, new DateTimeImmutable('2026-11-20 12:00:00')));

    app(MarkOverdueInvoicesHandler::class)->handle();

    expect(app(MarkOverdueInvoicesHandler::class)->handle())->toBe(0)
        ->and(billingOutboxRows(42))->toHaveCount(2);
});

test('stan konta po odczycie zgadza się ze snapshotem z zapisu', function () {
    app(IssueInvoiceHandler::class)->handle(new IssueInvoice(42, 10000, new DateTimeImmutable('2026-11-20 12:00:00')));
    app(MarkOverdueInvoicesHandler::class)->handle();

    $account = app(BillingAccountRepository::class)->getOrOpen(42);

    expect($account->version())->toBe(2)
        ->and($account->snapshot())->toMatchArray(['balance_cents' => 10000, 'overdue_cents' => 10000, 'open_invoices' => 1])
        ->and($account->pendingEvents())->toBe([]);
});

test('zapis ze starą wersją rzuca ConcurrencyConflict i nie zostawia outboxu', function () {
    $repo = new SqlBillingAccountRepository;
    $id = app(IssueInvoiceHandler::class)->handle(new IssueInvoice(42, 10000, new DateTimeImmutable('2026-12-20 12:00:00')));

    $a = $repo->getOrOpen(42);
    $b = $repo->getOrOpen(42);
    $a->recordPayment($id, new DateTimeImmutable('2026-11-21 12:00:00'));
    $repo->save($a);

    $b->recordPayment($id, new DateTimeImmutable('2026-11-21 12:00:00'));

    expect(fn () => $repo->save($b))->toThrow(ConcurrencyConflict::class)
        ->and(billingOutboxRows(42))->toHaveCount(2);
});

test('równoległe otwarcie tego samego konta przegrywa jako ConcurrencyConflict', function () {
    $repo = new SqlBillingAccountRepository;
    $a = $repo->getOrOpen(42);
    $b = $repo->getOrOpen(42);
    $a->issueInvoice(InvoiceId::fromString('A'), 100, new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-12-01'));
    $b->issueInvoice(InvoiceId::fromString('B'), 100, new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-12-01'));
    $repo->save($a);

    expect(fn () => $repo->save($b))->toThrow(ConcurrencyConflict::class);
});
