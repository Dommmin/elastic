<?php

use App\Models\Offer;
use App\Models\Seller;
use Billing\Application\IssueInvoice;
use Billing\Application\IssueInvoiceHandler;
use Billing\Application\MarkOverdueInvoicesHandler;
use Illuminate\Support\Carbon;

dataset('export endpoints', [
    'sellers' => '/api/internal/sellers',
    'offer-stats' => '/api/internal/sellers/1/offer-stats',
    'billing' => '/api/internal/billing/accounts',
]);

test('bez tokenu odpowiada 401', function (string $url) {
    $this->flushHeaders();

    $this->getJson($url)->assertUnauthorized();
})->with('export endpoints');

test('sellers stronicuje keysetem (3 sprzedawców, limit 2)', function () {
    $ids = Seller::factory()->count(3)->create()->pluck('id')->sort()->values()->all();

    $first = $this->getJson('/api/internal/sellers?limit=2')->assertOk()->json();

    expect(array_column($first['items'], 'seller_id'))->toBe([$ids[0], $ids[1]])
        ->and($first['next_after'])->toBe($ids[1]);

    $second = $this->getJson('/api/internal/sellers?limit=2&after='.$first['next_after'])->assertOk()->json();

    expect(array_column($second['items'], 'seller_id'))->toBe([$ids[2]])
        ->and($second['next_after'])->toBeNull();
});

test('sellers zwraca pola profilu, status i wersję', function () {
    $seller = Seller::factory()->blocked()->create(['synthetic' => true]);
    $seller->forceFill(['status_reason' => 'oszustwo'])->save();

    $item = $this->getJson('/api/internal/sellers')->assertOk()->json('items.0');

    expect($item)->toBe([
        'seller_id' => $seller->id,
        'name' => $seller->name,
        'city' => $seller->city,
        'nip' => $seller->nip,
        'status' => 'blocked',
        'status_reason' => 'oszustwo',
        'synthetic' => true,
        'version' => $seller->fresh()->version,
    ]);
});

test('limit poza zakresem daje 422', function (string $url) {
    $this->getJson($url.'?limit=5001')->assertStatus(422);
})->with(['/api/internal/sellers', '/api/internal/billing/accounts']);

test('offer-stats liczy statusy ofert z fabryk', function () {
    $seller = Seller::factory()->create();
    Offer::factory()->count(2)->create(['seller_id' => $seller->id]);
    Offer::factory()->soldOut()->create(['seller_id' => $seller->id]);
    Offer::factory()->create(['seller_id' => $seller->id, 'status' => 'suspended']);
    Offer::factory()->draft()->create(['seller_id' => $seller->id]);
    Offer::factory()->withdrawn()->create(['seller_id' => $seller->id]);
    Offer::factory()->create(); // cudza oferta

    $json = $this->getJson("/api/internal/sellers/{$seller->id}/offer-stats")->assertOk()->json();

    expect($json)->toMatchArray(['seller_id' => $seller->id, 'active' => 2, 'suspended' => 1, 'sold_out' => 1, 'total' => 6])
        ->and($json['computed_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}[+-]\d{2}:\d{2}$/');
});

test('offer-stats dla sprzedawcy bez ofert zwraca zera', function () {
    $this->getJson('/api/internal/sellers/999/offer-stats')
        ->assertOk()
        ->assertJsonPath('total', 0)
        ->assertJsonPath('active', 0);
});

test('billing/accounts zwraca snapshot i wersję oraz stronicuje', function () {
    Carbon::setTestNow('2026-11-21 12:00:00');
    $issue = app(IssueInvoiceHandler::class);
    $issue->handle(new IssueInvoice(10, 10000, new DateTimeImmutable('2026-11-20 12:00:00')));
    $issue->handle(new IssueInvoice(10, 500, new DateTimeImmutable('2026-12-20 12:00:00')));
    $issue->handle(new IssueInvoice(11, 700, new DateTimeImmutable('2026-12-20 12:00:00')));
    $issue->handle(new IssueInvoice(12, 900, new DateTimeImmutable('2026-12-20 12:00:00')));
    app(MarkOverdueInvoicesHandler::class)->handle();

    $first = $this->getJson('/api/internal/billing/accounts?limit=2')->assertOk()->json();

    expect($first['items'])->toHaveCount(2)
        ->and($first['next_after'])->toBe(11)
        ->and($first['items'][0])->toMatchArray(['seller_id' => 10, 'balance_cents' => 10500, 'overdue_cents' => 10000, 'open_invoices' => 2, 'version' => 3])
        ->and($first['items'][0]['overdue_since'])->toStartWith('2026-11-20')
        ->and($first['items'][1])->toMatchArray(['seller_id' => 11, 'balance_cents' => 700, 'overdue_since' => null, 'version' => 1]);

    $second = $this->getJson('/api/internal/billing/accounts?limit=2&after=11')->assertOk()->json();

    expect(array_column($second['items'], 'seller_id'))->toBe([12])
        ->and($second['next_after'])->toBeNull();

    Carbon::setTestNow();
});
