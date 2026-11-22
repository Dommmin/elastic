<?php

use App\Models\User;
use App\Support\TaxIdGenerator;
use Illuminate\Support\Facades\DB;
use SellerPortal\Domain\Seller\City;
use SellerPortal\Domain\Seller\Seller;
use SellerPortal\Domain\Seller\SellerName;
use SellerPortal\Domain\Seller\TaxId;
use SellerPortal\Domain\Shared\ModeratorId;
use SellerPortal\Domain\Shared\Reason;
use SellerPortal\Domain\Shared\UserId;
use SellerPortal\Infrastructure\Persistence\SqlSellerRepository;
use SharedKernel\ConcurrencyConflict;

function sellerOutboxRegister(SqlSellerRepository $repo, bool $synthetic = false): Seller
{
    $id = $repo->nextIdentity();
    $seller = Seller::register(
        $id,
        UserId::fromInt(User::factory()->create()->id),
        SellerName::fromString('Sklep Outbox'),
        City::fromString('Poznań'),
        TaxId::fromString(TaxIdGenerator::forSeed($id->value())),
        $synthetic,
        new DateTimeImmutable('2026-10-15 12:00:00'),
    );
    $repo->save($seller);

    return $seller;
}

/** @return list<object> */
function sellerOutboxRows(int $sellerId): array
{
    return DB::table('outbox')->where('aggregate_type', 'seller')->where('aggregate_id', (string) $sellerId)
        ->orderBy('sequence')->orderBy('event_id')->get()->all();
}

test('rejestracja daje jeden wiersz seller.registered z version 1 i nip', function () {
    $repo = new SqlSellerRepository;
    $seller = sellerOutboxRegister($repo, synthetic: true);

    $rows = sellerOutboxRows($seller->id()->value());
    expect($rows)->toHaveCount(1);

    $row = $rows[0];
    $payload = json_decode($row->payload, true);

    expect($row->event_type)->toBe('seller.registered')
        ->and((int) $row->sequence)->toBe(1)
        ->and($row->event_id)->toHaveLength(26)
        ->and($payload['seller_id'])->toBe($seller->id()->value())
        ->and($payload['name'])->toBe('Sklep Outbox')
        ->and($payload['city'])->toBe('Poznań')
        ->and($payload['nip'])->toBe($seller->taxId()->value())
        ->and($payload['status'])->toBe('pending')
        ->and($payload['status_reason'])->toBeNull()
        ->and($payload['synthetic'])->toBeTrue()
        ->and($payload['version'])->toBe(1)
        // ISO 8601 z offsetem i mikrosekundami: konsument nie zgaduje strefy (format SQL bez strefy był niejednoznaczny).
        ->and($payload['occurred_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}\+00:00$/');
});

test('approve i rename w kolejnych zapisach dają version 2 i 3', function () {
    $repo = new SqlSellerRepository;
    $seller = sellerOutboxRegister($repo);
    $id = $seller->id();

    $a = $repo->get($id);
    $a->approve(ModeratorId::fromInt(1), new DateTimeImmutable('2026-10-15 13:00:00'));
    $repo->save($a);

    $b = $repo->get($id);
    $b->rename(SellerName::fromString('Nowa Nazwa'), new DateTimeImmutable('2026-10-15 14:00:00'));
    $repo->save($b);

    $rows = sellerOutboxRows($id->value());
    $payloads = array_map(fn ($r) => json_decode($r->payload, true), $rows);

    expect(array_map(fn ($r) => $r->event_type, $rows))->toBe(['seller.registered', 'seller.approved', 'seller.renamed'])
        ->and(array_column($payloads, 'version'))->toBe([1, 2, 3])
        ->and($payloads[1]['status'])->toBe('active')
        ->and($payloads[2]['name'])->toBe('Nowa Nazwa');
});

test('block niesie status_reason, relocate daje seller.relocated', function () {
    $repo = new SqlSellerRepository;
    $seller = sellerOutboxRegister($repo);

    $a = $repo->get($seller->id());
    $a->approve(ModeratorId::fromInt(1), new DateTimeImmutable('2026-10-15 13:00:00'));
    $a->block(ModeratorId::fromInt(1), Reason::fromString('Oszustwo'), new DateTimeImmutable('2026-10-15 13:30:00'));
    $repo->save($a);

    $b = $repo->get($seller->id());
    $b->relocate(City::fromString('Gdańsk'), new DateTimeImmutable('2026-10-15 14:00:00'));
    $repo->save($b);

    $rows = sellerOutboxRows($seller->id()->value());
    $blocked = json_decode($rows[2]->payload, true);
    $relocated = json_decode($rows[3]->payload, true);

    // Dwa zdarzenia z jednego zapisu dzielą version 2.
    expect(array_map(fn ($r) => $r->event_type, $rows))->toBe(['seller.registered', 'seller.approved', 'seller.blocked', 'seller.relocated'])
        ->and($blocked['status_reason'])->toBe('Oszustwo')
        ->and($blocked['version'])->toBe(2)
        ->and($relocated['city'])->toBe('Gdańsk')
        ->and($relocated['version'])->toBe(3);
});

test('nieudany zapis (ConcurrencyConflict) nie zostawia wiersza w outboxie', function () {
    $repo = new SqlSellerRepository;
    $seller = sellerOutboxRegister($repo);

    $a = $repo->get($seller->id());
    $b = $repo->get($seller->id());
    $a->approve(ModeratorId::fromInt(1), new DateTimeImmutable('2026-10-15 13:00:00'));
    $b->reject(ModeratorId::fromInt(2), Reason::fromString('Brak danych'), new DateTimeImmutable('2026-10-15 13:00:01'));
    $repo->save($a);

    expect(fn () => $repo->save($b))->toThrow(ConcurrencyConflict::class);

    $types = array_map(fn ($r) => $r->event_type, sellerOutboxRows($seller->id()->value()));
    expect($types)->toBe(['seller.registered', 'seller.approved']);
});
