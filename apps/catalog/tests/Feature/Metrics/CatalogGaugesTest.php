<?php

use App\Metrics\CatalogGauges;
use App\Models\Offer;
use App\Models\Seller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-17 12:00:00', 'UTC'));
});

afterEach(fn () => Carbon::setTestNow());

function gauges(): CatalogGauges
{
    return app(CatalogGauges::class);
}

function gaugeOrder(string $status, int $placedSecondsAgo = 0): int
{
    $buyerId = DB::table('users')->insertGetId([
        'name' => 'Kupujący',
        'email' => fake()->unique()->safeEmail(),
        'password' => 'x',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return DB::table('orders')->insertGetId([
        'buyer_user_id' => $buyerId,
        'seller_id' => Seller::factory()->create()->id,
        'status' => $status,
        'currency' => 'PLN',
        'total_cents' => 10000,
        'placed_at' => now()->subSeconds($placedSecondsAgo),
        'payment_deadline' => now()->addHour(),
        'version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function gaugeReservation(int $orderId, string $status): void
{
    DB::table('offer_reservations')->insert([
        'offer_id' => Offer::factory()->create()->id,
        'reservation_key' => 'order-'.$orderId,
        'quantity' => 1,
        'status' => $status,
        'reserved_at' => now(),
    ]);
}

function gaugeOutbox(?int $occurredSecondsAgo, bool $published): void
{
    DB::table('outbox')->insert([
        'event_id' => (string) Str::ulid(),
        'aggregate_type' => 'offer',
        'aggregate_id' => '1',
        'event_type' => 'OfferUpdated',
        'payload' => '{}',
        'sequence' => 1,
        'occurred_at' => now()->subSeconds($occurredSecondsAgo),
        'published_at' => $published ? now() : null,
    ]);
}

test('pusta baza: liczniki i wieki to 0.0, sweep bez próbki', function () {
    $g = gauges();

    expect($g->outboxPending())->toBe(0.0)
        ->and($g->outboxOldestPendingAgeSeconds())->toBe(0.0)
        ->and($g->offersPendingModeration())->toBe(0.0)
        ->and($g->offersPendingModerationOldestAgeSeconds())->toBe(0.0)
        ->and($g->sellersPendingApproval())->toBe(0.0)
        ->and($g->sellersPendingApprovalOldestAgeSeconds())->toBe(0.0)
        ->and($g->ordersStuckPlaced())->toBe(0.0)
        ->and($g->ordersSettledWithActiveReservation())->toBe(0.0)
        ->and($g->orderingSweepLastRunAgeSeconds())->toBeNull()
        ->and($g->failedJobs())->toBe(0.0);
});

test('outbox liczy tylko nieopublikowane, wiek to najstarszy z nich', function () {
    gaugeOutbox(10, false);
    gaugeOutbox(90, false);
    gaugeOutbox(500, true);

    expect(gauges()->outboxPending())->toBe(2.0)
        ->and(gauges()->outboxOldestPendingAgeSeconds())->toBe(90.0);
});

test('oferta z oczekującą zmianą ceny czeka na moderację', function () {
    Offer::factory()->create(['pending_requested_at' => now()->subHours(2)]);
    Offer::factory()->create();

    expect(gauges()->offersPendingModeration())->toBe(1.0)
        ->and(gauges()->offersPendingModerationOldestAgeSeconds())->toBe(7200.0);
});

test('sprzedawca pending bez status_changed_at liczy wiek od created_at', function () {
    Seller::factory()->pending()->create([
        'status_changed_at' => null,
        'created_at' => now()->subHours(3),
    ]);
    Seller::factory()->create();

    expect(gauges()->sellersPendingApproval())->toBe(1.0)
        ->and(gauges()->sellersPendingApprovalOldestAgeSeconds())->toBe(10800.0);
});

test('zamówienie placed utknęło dopiero po progu; awaiting_payment się nie liczy', function () {
    gaugeOrder('placed', 59);
    gaugeOrder('placed', 61);
    gaugeOrder('awaiting_payment', 600);

    expect(CatalogGauges::STUCK_PLACED_AFTER_SECONDS)->toBe(60)
        ->and(gauges()->ordersStuckPlaced())->toBe(1.0);
});

test('aktywna rezerwacja przy rozliczonym zamówieniu to rozjazd', function (string $orderStatus, string $reservation, float $expected) {
    gaugeReservation(gaugeOrder($orderStatus), $reservation);

    expect(gauges()->ordersSettledWithActiveReservation())->toBe($expected);
})->with([
    'paid + active' => ['paid', 'active', 1.0],
    'paid + confirmed' => ['paid', 'confirmed', 0.0],
    'awaiting_payment + active' => ['awaiting_payment', 'active', 0.0],
    'shipped + active' => ['shipped', 'active', 1.0],
]);

test('sweep: wiek ostatniego przebiegu z cache', function () {
    Cache::put('ordering:sweep:last_run_at', now()->subSeconds(200)->toIso8601String());

    expect(gauges()->orderingSweepLastRunAgeSeconds())->toBe(200.0);
});

test('failed_jobs liczy wiersze', function () {
    foreach ([1, 2] as $i) {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'rabbitmq',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'x',
            'failed_at' => now(),
        ]);
    }

    expect(gauges()->failedJobs())->toBe(2.0);
});
