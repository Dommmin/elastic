<?php

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use SellerPortal\Application\Offer\DraftOffer;
use SellerPortal\Application\Offer\DraftOfferHandler;
use SellerPortal\Application\Offer\PublishOffer;
use SellerPortal\Application\Offer\PublishOfferHandler;
use SellerPortal\Application\Policy\ReinstateOffersOfUnblockedSeller;
use SellerPortal\Application\Policy\SuspendOffersOfBlockedSeller;
use SellerPortal\Application\Port\DomainEventDispatcher;
use SellerPortal\Application\Seller\ApproveSeller;
use SellerPortal\Application\Seller\ApproveSellerHandler;
use SellerPortal\Application\Seller\BlockSeller;
use SellerPortal\Application\Seller\BlockSellerHandler;
use SellerPortal\Application\Seller\RegisterSeller;
use SellerPortal\Application\Seller\RegisterSellerHandler;
use SellerPortal\Application\Seller\UnblockSeller;
use SellerPortal\Application\Seller\UnblockSellerHandler;
use SellerPortal\Domain\Seller\Event\SellerBlocked;
use SellerPortal\Domain\Seller\SellerId;
use SellerPortal\Infrastructure\Job\ReinstateOffersOfUnblockedSellerJob;
use SellerPortal\Infrastructure\Job\SuspendOffersOfBlockedSellerJob;

beforeEach(function () {
    // `sync`: joby wykonują się od razu w transakcji handlera. Test atomowości
    // przełącza się na `database` sam.
    config(['queue.default' => 'sync']);
});

/**
 * @return array{0: SellerId, 1: int} sprzedawca Active i ID moderatora
 */
function policyJobsActiveSeller(): array
{
    $moderator = User::factory()->create()->id;
    $id = app(RegisterSellerHandler::class)->handle(new RegisterSeller(User::factory()->create()->id, 'Sklep Polityk', 'Poznań'));
    app(ApproveSellerHandler::class)->handle(new ApproveSeller($id->value(), $moderator));

    return [$id, $moderator];
}

function policyJobsOffer(SellerId $seller, bool $publish): int
{
    $userId = (int) DB::table('sellers')->where('id', $seller->value())->value('user_id');
    $id = app(DraftOfferHandler::class)->handle(new DraftOffer($userId, Product::factory()->create()->id, 10000, 5, 'new', 3));

    if ($publish) {
        app(PublishOfferHandler::class)->handle(new PublishOffer($userId, $id->value()));
    }

    return $id->value();
}

function policyJobsBlock(SellerId $seller, int $moderator): void
{
    app(BlockSellerHandler::class)->handle(new BlockSeller($seller->value(), $moderator, 'Oszustwo'));
}

function policyJobsUnblock(SellerId $seller, int $moderator): void
{
    app(UnblockSellerHandler::class)->handle(new UnblockSeller($seller->value(), $moderator));
}

function policyJobsStatus(int $offerId): string
{
    return DB::table('offers')->where('id', $offerId)->value('status');
}

test('blokada kolejkuje job w tej samej transakcji', function () {
    Queue::fake();
    [$seller, $moderator] = policyJobsActiveSeller();

    policyJobsBlock($seller, $moderator);

    Queue::assertPushed(SuspendOffersOfBlockedSellerJob::class, fn ($job) => $job->sellerId === $seller->value());
});

test('odblokowanie kolejkuje job przywracania', function () {
    Queue::fake();
    [$seller, $moderator] = policyJobsActiveSeller();
    policyJobsBlock($seller, $moderator);

    policyJobsUnblock($seller, $moderator);

    Queue::assertPushed(ReinstateOffersOfUnblockedSellerJob::class, fn ($job) => $job->sellerId === $seller->value());
});

test('wyjątek po blokadzie wycofuje i blokadę, i job', function () {
    config(['queue.default' => 'database']);
    [$seller, $moderator] = policyJobsActiveSeller();
    app(DomainEventDispatcher::class)->listen(SellerBlocked::class, function () {
        throw new RuntimeException('awaria po kolejkowaniu');
    });

    expect(fn () => policyJobsBlock($seller, $moderator))->toThrow(RuntimeException::class);

    expect(DB::table('sellers')->where('id', $seller->value())->value('status'))->toBe('active')
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('bez wyjątku blokada zostawia w tabeli jobs dokładnie jeden job', function () {
    config(['queue.default' => 'database']);
    [$seller, $moderator] = policyJobsActiveSeller();

    policyJobsBlock($seller, $moderator);

    expect(DB::table('jobs')->count())->toBe(1);
});

test('słuchacze polityk przeżywają flush instancji scoped (Octane)', function () {
    Queue::fake();
    [$seller, $moderator] = policyJobsActiveSeller();
    app(DomainEventDispatcher::class);
    app()->forgetScopedInstances();

    policyJobsBlock($seller, $moderator);

    Queue::assertPushed(SuspendOffersOfBlockedSellerJob::class);
});

test('job zawiesza tylko opublikowane oferty sprzedawcy', function () {
    [$seller, $moderator] = policyJobsActiveSeller();
    $published = policyJobsOffer($seller, publish: true);
    $draft = policyJobsOffer($seller, publish: false);
    [$other] = policyJobsActiveSeller();
    $foreign = policyJobsOffer($other, publish: true);
    DB::table('outbox')->delete();

    policyJobsBlock($seller, $moderator);

    $event = DB::table('outbox')->where('event_type', 'offer.suspended')->get();

    expect(policyJobsStatus($published))->toBe('suspended')
        ->and(policyJobsStatus($draft))->toBe('draft')
        ->and(policyJobsStatus($foreign))->toBe('published')
        ->and($event)->toHaveCount(1)
        ->and(json_decode($event[0]->payload, true))->toMatchArray(['id' => $published, 'suspension_kind' => 'seller_blocked']);
});

test('odblokowanie przywraca tylko zawieszone przez blokadę', function () {
    [$seller, $moderator] = policyJobsActiveSeller();
    $byBlock = policyJobsOffer($seller, publish: true);
    $byModerator = policyJobsOffer($seller, publish: true);
    DB::table('offers')->where('id', $byModerator)->update(['status' => 'suspended', 'suspension_kind' => 'moderation', 'suspended_by' => $moderator, 'suspension_note' => 'Podejrzana cena']);
    policyJobsBlock($seller, $moderator);

    policyJobsUnblock($seller, $moderator);

    expect(policyJobsStatus($byBlock))->toBe('published')
        ->and(policyJobsStatus($byModerator))->toBe('suspended');
});

test('job po szybkim odblokowaniu nic nie robi', function () {
    Queue::fake();
    [$seller, $moderator] = policyJobsActiveSeller();
    $offer = policyJobsOffer($seller, publish: true);
    policyJobsBlock($seller, $moderator);
    policyJobsUnblock($seller, $moderator);
    DB::table('outbox')->delete();

    $suspended = app(SuspendOffersOfBlockedSeller::class)->run($seller);

    expect($suspended)->toBe(0)
        ->and(policyJobsStatus($offer))->toBe('published')
        ->and(DB::table('outbox')->count())->toBe(0);
});

test('ponowne uruchomienie joba jest idempotentne', function () {
    Queue::fake();
    [$seller, $moderator] = policyJobsActiveSeller();
    $offer = policyJobsOffer($seller, publish: true);
    policyJobsBlock($seller, $moderator);

    $first = app(SuspendOffersOfBlockedSeller::class)->run($seller);
    $outbox = DB::table('outbox')->count();
    $second = app(SuspendOffersOfBlockedSeller::class)->run($seller);

    expect($first)->toBe(1)
        ->and($second)->toBe(0)
        ->and(policyJobsStatus($offer))->toBe('suspended')
        ->and(DB::table('outbox')->count())->toBe($outbox);

    policyJobsUnblock($seller, $moderator);
    expect(app(ReinstateOffersOfUnblockedSeller::class)->run($seller))->toBe(1)
        ->and(app(ReinstateOffersOfUnblockedSeller::class)->run($seller))->toBe(0);
});
