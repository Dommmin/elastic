<?php

use App\Models\User;
use App\Support\TaxIdGenerator;
use Illuminate\Support\Facades\DB;
use SellerPortal\Application\SellerAlreadyRegistered;
use SellerPortal\Application\TaxIdAlreadyTaken;
use SellerPortal\Domain\Seller\City;
use SellerPortal\Domain\Seller\Seller;
use SellerPortal\Domain\Seller\SellerId;
use SellerPortal\Domain\Seller\SellerName;
use SellerPortal\Domain\Seller\SellerNotFound;
use SellerPortal\Domain\Seller\SellerStatus;
use SellerPortal\Domain\Seller\TaxId;
use SellerPortal\Domain\Shared\ModeratorId;
use SellerPortal\Domain\Shared\Reason;
use SellerPortal\Domain\Shared\UserId;
use SellerPortal\Infrastructure\Persistence\SqlSellerRepository;
use SharedKernel\ConcurrencyConflict;

function sellerRepoRegister(SqlSellerRepository $repo, ?User $user = null, string $name = 'Sklep Śródmieście', ?DateTimeImmutable $at = null, ?string $nip = null, bool $synthetic = false): Seller
{
    $user ??= User::factory()->create();
    $id = $repo->nextIdentity();
    $seller = Seller::register(
        $id,
        UserId::fromInt($user->id),
        SellerName::fromString($name),
        City::fromString('Poznań'),
        TaxId::fromString($nip ?? TaxIdGenerator::forSeed($id->value())),
        $synthetic,
        $at ?? new DateTimeImmutable('2026-10-15 12:00:00'),
    );
    $repo->save($seller);

    return $seller;
}

test('zapis i odczyt sprzedawcy zachowuje pola, a nowy ma w bazie wersję 1', function () {
    $repo = new SqlSellerRepository;
    $user = User::factory()->create();
    $seller = sellerRepoRegister($repo, $user);

    $loaded = $repo->get($seller->id());

    expect($loaded->version())->toBe(1)
        ->and($loaded->userId()->value())->toBe($user->id)
        ->and($loaded->name()->value())->toBe('Sklep Śródmieście')
        ->and($loaded->city()->value())->toBe('Poznań')
        ->and($loaded->status())->toBe(SellerStatus::Pending)
        ->and($loaded->statusReason())->toBeNull()
        ->and($repo->findByUserId(UserId::fromInt($user->id))->id()->equals($seller->id()))->toBeTrue()
        ->and($repo->findByUserId(UserId::fromInt($user->id + 100)))->toBeNull();
});

test('brakujący sprzedawca rzuca SellerNotFound', function () {
    (new SqlSellerRepository)->get(SellerId::fromInt(999));
})->throws(SellerNotFound::class);

test('slug sprzedawcy = slug nazwy + id', function () {
    $seller = sellerRepoRegister(new SqlSellerRepository, name: 'Sklep Śródmieście');
    $row = DB::table('sellers')->where('id', $seller->id()->value())->first();

    expect($row->slug)->toBe('sklep-srodmiescie-'.$seller->id()->value())
        ->and((int) $row->rating)->toBe(0)
        ->and($row->lat)->toBeNull()
        ->and($row->lon)->toBeNull();
});

test('zapis sprzedawcy nie tworzy wiersza outboxu', function () {
    $repo = new SqlSellerRepository;
    $registered = sellerRepoRegister($repo);
    $seller = $repo->get($registered->id());
    $seller->approve(ModeratorId::fromInt(1), new DateTimeImmutable('2026-10-15 13:00:00'));
    $repo->save($seller);

    expect(DB::table('outbox')->count())->toBe(0);
});

test('status_changed_at to czas ostatniego zdarzenia, a status i powód się zapisują', function () {
    $repo = new SqlSellerRepository;
    $seller = sellerRepoRegister($repo, at: new DateTimeImmutable('2026-10-15 12:00:00'));

    $loaded = $repo->get($seller->id());
    $loaded->approve(ModeratorId::fromInt(1), new DateTimeImmutable('2026-10-15 13:00:00'));
    $loaded->block(ModeratorId::fromInt(1), Reason::fromString('Oszustwo'), new DateTimeImmutable('2026-10-15 14:30:00'));
    $repo->save($loaded);

    $row = DB::table('sellers')->where('id', $seller->id()->value())->first();
    $reloaded = $repo->get($seller->id());

    expect($row->status_changed_at)->toStartWith('2026-10-15 14:30:00')
        ->and($reloaded->version())->toBe(2)
        ->and($reloaded->status())->toBe(SellerStatus::Blocked)
        ->and($reloaded->statusReason()->value())->toBe('Oszustwo');
});

test('drugi zapis tej samej wersji sprzedawcy rzuca ConcurrencyConflict', function () {
    $repo = new SqlSellerRepository;
    $seller = sellerRepoRegister($repo);

    $a = $repo->get($seller->id());
    $b = $repo->get($seller->id());
    $a->approve(ModeratorId::fromInt(1), new DateTimeImmutable('2026-10-15 13:00:00'));
    $b->reject(ModeratorId::fromInt(2), Reason::fromString('Brak danych'), new DateTimeImmutable('2026-10-15 13:00:01'));

    $repo->save($a);

    expect(fn () => $repo->save($b))->toThrow(ConcurrencyConflict::class)
        ->and($repo->get($seller->id())->status())->toBe(SellerStatus::Active);
});

test('druga rejestracja tego samego użytkownika rzuca SellerAlreadyRegistered, nie surowy QueryException', function () {
    $repo = new SqlSellerRepository;
    $user = User::factory()->create();
    sellerRepoRegister($repo, $user);

    expect(fn () => sellerRepoRegister($repo, $user, name: 'Drugi Sklep'))
        ->toThrow(SellerAlreadyRegistered::class)
        ->and(DB::table('sellers')->where('user_id', $user->id)->count())->toBe(1);
});

test('NIP i flaga synthetic przechodzą przez zapis i odczyt', function () {
    $repo = new SqlSellerRepository;
    $seller = sellerRepoRegister($repo, nip: '526-104-08-28', synthetic: true);

    $loaded = $repo->get($seller->id());
    $row = DB::table('sellers')->where('id', $seller->id()->value())->first();

    expect($loaded->taxId()->value())->toBe('5261040828')
        ->and($loaded->isSynthetic())->toBeTrue()
        ->and($row->nip)->toBe('5261040828')
        ->and((bool) $row->synthetic)->toBeTrue();
});

test('zmiana nazwy i miasta zapisuje się w bazie i podnosi wersję', function () {
    $repo = new SqlSellerRepository;
    $registered = sellerRepoRegister($repo);

    $seller = $repo->get($registered->id());
    $seller->rename(SellerName::fromString('Nowa Nazwa'), new DateTimeImmutable('2026-10-15 13:00:00'));
    $seller->relocate(City::fromString('Gdańsk'), new DateTimeImmutable('2026-10-15 13:00:01'));
    $repo->save($seller);

    $reloaded = $repo->get($registered->id());

    expect($reloaded->name()->value())->toBe('Nowa Nazwa')
        ->and($reloaded->city()->value())->toBe('Gdańsk')
        ->and($reloaded->version())->toBe(2);
});

test('ten sam NIP u drugiego sprzedawcy rzuca TaxIdAlreadyTaken, nie surowy QueryException', function () {
    $repo = new SqlSellerRepository;
    sellerRepoRegister($repo, nip: '5261040828');

    expect(fn () => sellerRepoRegister($repo, nip: '5261040828'))->toThrow(TaxIdAlreadyTaken::class);
});
