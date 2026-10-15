<?php

use SellerPortal\Application\Moderation\SuspendOffer;
use SellerPortal\Application\Moderation\SuspendOfferHandler;
use SellerPortal\Application\Offer\PublishOffer;
use SellerPortal\Application\Offer\PublishOfferHandler;
use SellerPortal\Application\Policy\QueueReinstatementWhenSellerUnblocked;
use SellerPortal\Application\Policy\QueueSuspensionWhenSellerBlocked;
use SellerPortal\Application\Policy\ReinstateOffersOfUnblockedSeller;
use SellerPortal\Application\Policy\SuspendOffersOfBlockedSeller;
use SellerPortal\Application\Port\PolicyJobQueue;
use SellerPortal\Application\Seller\BlockSeller;
use SellerPortal\Application\Seller\BlockSellerHandler;
use SellerPortal\Application\Seller\UnblockSeller;
use SellerPortal\Application\Seller\UnblockSellerHandler;
use SellerPortal\Domain\Offer\Event\OfferSuspended;
use SellerPortal\Domain\Offer\Offer;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Offer\OfferRepository;
use SellerPortal\Domain\Offer\OfferStatus;
use SellerPortal\Domain\Offer\ProductId;
use SellerPortal\Domain\Offer\SuspensionKind;
use SellerPortal\Domain\Seller\Event\SellerBlocked;
use SellerPortal\Domain\Seller\Event\SellerUnblocked;
use SellerPortal\Domain\Seller\SellerId;
use SellerPortal\Domain\Shared\ConcurrencyConflict;
use SellerPortal\Domain\Shared\ModeratorId;
use SellerPortal\Domain\Shared\Reason;
use Tests\Unit\SellerPortal\InMemory\InMemoryOfferRepository;
use Tests\Unit\SellerPortal\InMemory\PortalWorld;

function sellerPoliciesPublished(PortalWorld $w, int $userId, int $productId): OfferId
{
    $id = $w->draft($userId, productId: $productId);
    (new PublishOfferHandler($w->transactions, $w->clock, $w->dispatcher, $w->access, $w->offers))->handle(new PublishOffer($userId, $id->value()));

    return $id;
}

function sellerPoliciesBlock(PortalWorld $w, SellerId $seller): void
{
    (new BlockSellerHandler($w->transactions, $w->clock, $w->dispatcher, $w->sellers))->handle(new BlockSeller($seller->value(), 900, 'Oszustwo'));
}

function sellerPoliciesUnblock(PortalWorld $w, SellerId $seller): void
{
    (new UnblockSellerHandler($w->transactions, $w->clock, $w->dispatcher, $w->sellers))->handle(new UnblockSeller($seller->value(), 900));
}

function sellerPoliciesSuspender(PortalWorld $w, ?OfferRepository $offers = null): SuspendOffersOfBlockedSeller
{
    return new SuspendOffersOfBlockedSeller($w->transactions, $w->clock, $w->dispatcher, $w->sellers, $offers ?? $w->offers);
}

function sellerPoliciesReinstater(PortalWorld $w): ReinstateOffersOfUnblockedSeller
{
    return new ReinstateOffersOfUnblockedSeller($w->transactions, $w->clock, $w->dispatcher, $w->sellers, $w->offers);
}

test('listenery tylko kolejkują job dla sprzedawcy ze zdarzenia', function () {
    $queue = new class implements PolicyJobQueue
    {
        /** @var list<string> */
        public array $calls = [];

        public function suspendOffersOf(SellerId $sellerId): void
        {
            $this->calls[] = 'suspend:'.$sellerId->value();
        }

        public function reinstateOffersOf(SellerId $sellerId): void
        {
            $this->calls[] = 'reinstate:'.$sellerId->value();
        }
    };
    $now = new DateTimeImmutable('2026-10-15 12:00:00');

    (new QueueSuspensionWhenSellerBlocked($queue))(new SellerBlocked(SellerId::fromInt(4), ModeratorId::fromInt(9), Reason::fromString('x'), $now));
    (new QueueReinstatementWhenSellerUnblocked($queue))(new SellerUnblocked(SellerId::fromInt(4), ModeratorId::fromInt(9), $now));

    expect($queue->calls)->toBe(['suspend:4', 'reinstate:4']);
});

test('zawieszenie dotyka tylko opublikowanych ofert sprzedawcy', function () {
    $w = new PortalWorld;
    $seller = $w->activeSeller(7);
    $w->activeSeller(8);
    $published = sellerPoliciesPublished($w, 7, 1);
    $draft = $w->draft(7, productId: 2);
    $withdrawn = sellerPoliciesPublished($w, 7, 3);
    $withdrawnOffer = $w->offers->get($withdrawn);
    $withdrawnOffer->withdraw($w->clock->now());
    $w->offers->save($withdrawnOffer);
    $foreign = sellerPoliciesPublished($w, 8, 1);
    sellerPoliciesBlock($w, $seller);
    $w->forgetDispatched();

    $count = sellerPoliciesSuspender($w)->run($seller);

    expect($count)->toBe(1)
        ->and($w->offers->get($published)->status())->toBe(OfferStatus::Suspended)
        ->and($w->offers->get($published)->suspension()->kind())->toBe(SuspensionKind::SellerBlocked)
        ->and($w->offers->get($draft)->status())->toBe(OfferStatus::Draft)
        ->and($w->offers->get($withdrawn)->status())->toBe(OfferStatus::Withdrawn)
        ->and($w->offers->get($foreign)->status())->toBe(OfferStatus::Published)
        ->and($w->dispatcher->classes())->toBe([OfferSuspended::class]);
});

test('odblokowanie przywraca tylko oferty zawieszone przez blokadę', function () {
    $w = new PortalWorld;
    $seller = $w->activeSeller(7);
    $byBlock = sellerPoliciesPublished($w, 7, 1);
    $byModerator = sellerPoliciesPublished($w, 7, 2);
    (new SuspendOfferHandler($w->transactions, $w->clock, $w->dispatcher, $w->offers))->handle(new SuspendOffer(900, $byModerator->value(), 'Podejrzana cena'));
    sellerPoliciesBlock($w, $seller);
    sellerPoliciesSuspender($w)->run($seller);
    sellerPoliciesUnblock($w, $seller);

    $count = sellerPoliciesReinstater($w)->run($seller);

    expect($count)->toBe(1)
        ->and($w->offers->get($byBlock)->status())->toBe(OfferStatus::Published)
        ->and($w->offers->get($byModerator)->status())->toBe(OfferStatus::Suspended)
        ->and($w->offers->get($byModerator)->suspension()->kind())->toBe(SuspensionKind::Moderation);
});

test('job po szybkim odblokowaniu nic nie robi', function () {
    $w = new PortalWorld;
    $seller = $w->activeSeller(7);
    $id = sellerPoliciesPublished($w, 7, 1);
    sellerPoliciesBlock($w, $seller);
    sellerPoliciesUnblock($w, $seller);
    $w->forgetDispatched();

    $count = sellerPoliciesSuspender($w)->run($seller);

    expect($count)->toBe(0)
        ->and($w->offers->get($id)->status())->toBe(OfferStatus::Published)
        ->and($w->dispatcher->dispatched)->toBe([]);
});

test('job przywracania po ponownej blokadzie nic nie robi', function () {
    $w = new PortalWorld;
    $seller = $w->activeSeller(7);
    $id = sellerPoliciesPublished($w, 7, 1);
    sellerPoliciesBlock($w, $seller);
    sellerPoliciesSuspender($w)->run($seller);
    sellerPoliciesUnblock($w, $seller);
    sellerPoliciesBlock($w, $seller);

    expect(sellerPoliciesReinstater($w)->run($seller))->toBe(0)
        ->and($w->offers->get($id)->status())->toBe(OfferStatus::Suspended);
});

test('ponowne uruchomienie jest idempotentne i nie dubluje zdarzeń', function () {
    $w = new PortalWorld;
    $seller = $w->activeSeller(7);
    sellerPoliciesPublished($w, 7, 1);
    sellerPoliciesBlock($w, $seller);
    sellerPoliciesSuspender($w)->run($seller);
    $w->forgetDispatched();

    $second = sellerPoliciesSuspender($w)->run($seller);

    expect($second)->toBe(0)
        ->and($w->dispatcher->dispatched)->toBe([]);
});

test('konflikt wersji ponawia ofertę, a po trzech próbach oddaje błąd', function (int $failures, bool $throws) {
    $w = new PortalWorld;
    $seller = $w->activeSeller(7);
    $id = sellerPoliciesPublished($w, 7, 1);
    sellerPoliciesBlock($w, $seller);

    $flaky = new class($w->offers, $failures) implements OfferRepository
    {
        public function __construct(private InMemoryOfferRepository $inner, private int $failures) {}

        public function nextIdentity(): OfferId
        {
            return $this->inner->nextIdentity();
        }

        public function hasLiveOffer(SellerId $sellerId, ProductId $productId): bool
        {
            return $this->inner->hasLiveOffer($sellerId, $productId);
        }

        public function get(OfferId $id): Offer
        {
            return $this->inner->get($id);
        }

        public function save(Offer $offer): void
        {
            if ($this->failures-- > 0) {
                throw new ConcurrencyConflict;
            }
            $this->inner->save($offer);
        }

        public function idsForSellerPolicy(SellerId $sellerId, OfferStatus $status, ?SuspensionKind $suspensionKind, int $afterId, int $limit): array
        {
            return $this->inner->idsForSellerPolicy($sellerId, $status, $suspensionKind, $afterId, $limit);
        }
    };

    $run = fn () => sellerPoliciesSuspender($w, $flaky)->run($seller);

    if ($throws) {
        expect($run)->toThrow(ConcurrencyConflict::class);
    } else {
        expect($run())->toBe(1)
            ->and($w->offers->get($id)->status())->toBe(OfferStatus::Suspended);
    }
})->with([
    'dwa konflikty, trzecia próba przechodzi' => [2, false],
    'trzy konflikty, błąd do joba' => [3, true],
]);
