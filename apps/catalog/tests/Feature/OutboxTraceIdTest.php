<?php

use App\Models\Outbox;
use App\Models\Product;
use App\Models\Seller as SellerRow;
use App\Support\Tracing\TraceId;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use SellerPortal\Domain\Offer\Condition;
use SellerPortal\Domain\Offer\ProductId;
use SellerPortal\Domain\Offer\ShippingTime;
use SellerPortal\Domain\Offer\Stock;
use SellerPortal\Domain\Seller\City;
use SellerPortal\Domain\Seller\Seller;
use SellerPortal\Domain\Seller\SellerId;
use SellerPortal\Domain\Seller\SellerName;
use SellerPortal\Domain\Seller\SellerStatus;
use SellerPortal\Infrastructure\Persistence\SqlOfferRepository;
use SellerPortal\Infrastructure\SystemClock;
use SharedKernel\Money;

/**
 * trace_id z Context trafia do wiersza outboxu w obu ścieżkach zapisu
 * (trait EmitsOutboxEvents i OfferIntegrationEvents) — to on pojedzie
 * dalej jako nagłówek AMQP i połączy żądanie ze zdarzeniem w logach.
 */
function traceIdSavedOffer(): void
{
    $repo = new SqlOfferRepository(new SystemClock);
    $sellerId = SellerRow::factory()->create()->id;
    $seller = Seller::reconstitute(SellerId::fromInt($sellerId), null, SellerName::fromString('Sklep'), City::fromString('Poznań'), SellerStatus::Active, null, 1);
    $offer = $seller->draftOffer(
        $repo->nextIdentity(),
        ProductId::fromInt(Product::factory()->create()->id),
        Money::fromCents(10000),
        Stock::fromInt(5),
        Condition::New,
        ShippingTime::fromInt(3),
        new DateTimeImmutable,
    );
    $repo->save($offer);
}

test('trace_id z Context trafia do outboxu (EmitsOutboxEvents)', function () {
    $traceId = str_repeat('a', 32);
    Context::add(TraceId::CONTEXT_KEY, $traceId);

    Product::createWithOutbox(['name' => 'Telefon', 'ean' => '5901234123457'], 'product.created');

    expect(Outbox::first()->trace_id)->toBe($traceId);
});

test('trace_id z Context trafia do outboxu (OfferIntegrationEvents)', function () {
    $traceId = str_repeat('b', 32);
    Context::add(TraceId::CONTEXT_KEY, $traceId);

    traceIdSavedOffer();

    $rows = DB::table('outbox')->where('aggregate_type', 'offer')->get();
    expect($rows)->not->toBeEmpty()
        ->and($rows->pluck('trace_id')->unique()->all())->toBe([$traceId]);
});

test('bez Context wiersz dostaje wygenerowany trace_id', function () {
    Context::forget(TraceId::CONTEXT_KEY);

    Product::createWithOutbox(['name' => 'Telefon', 'ean' => '5901234123457'], 'product.created');

    expect(Outbox::first()->trace_id)->toMatch('/^[0-9a-f]{32}$/');
});
