<?php

use App\Models\Seller;
use Illuminate\Support\Facades\DB;

/**
 * `billing:churn` (ETAP 13, Task 14): nowe faktury dla losowych syntetycznych sprzedawców drogą domenową
 * (outbox `billing.invoice_issued`) — ruch billingowy pod przebudowę seller-360, gdy generator
 * `seller360:simulate` odmawia drugiego przebiegu.
 */
test('wystawia faktury tylko syntetycznym sprzedawcom przez outbox', function () {
    $synthetic = Seller::factory()->count(3)->create(['synthetic' => true]);
    $real = Seller::factory()->create();
    DB::table('outbox')->truncate();

    $this->artisan('billing:churn', ['--rate' => 20, '--duration' => 1])
        ->expectsOutputToContain('issued=')
        ->assertSuccessful();

    $events = DB::table('outbox')->where('event_type', 'billing.invoice_issued')->get();
    expect($events)->not->toBeEmpty()
        ->and($events->pluck('aggregate_id')->map(fn ($id) => (int) $id)->diff($synthetic->pluck('id'))->all())->toBe([])
        ->and(DB::table('billing_accounts')->where('seller_id', $real->id)->exists())->toBeFalse();
});

test('bez syntetycznych sprzedawców kończy się błędem zamiast cichego zera', function () {
    Seller::factory()->create();

    $this->artisan('billing:churn', ['--rate' => 5, '--duration' => 1])
        ->expectsOutputToContain('Brak syntetycznych sprzedawców')
        ->assertFailed();
});
