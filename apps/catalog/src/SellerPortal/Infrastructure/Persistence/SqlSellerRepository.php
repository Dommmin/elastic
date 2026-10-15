<?php

namespace SellerPortal\Infrastructure\Persistence;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SellerPortal\Application\SellerAlreadyRegistered;
use SellerPortal\Domain\Seller\City;
use SellerPortal\Domain\Seller\Seller;
use SellerPortal\Domain\Seller\SellerId;
use SellerPortal\Domain\Seller\SellerName;
use SellerPortal\Domain\Seller\SellerNotFound;
use SellerPortal\Domain\Seller\SellerRepository;
use SellerPortal\Domain\Seller\SellerStatus;
use SellerPortal\Domain\Shared\ConcurrencyConflict;
use SellerPortal\Domain\Shared\InvalidValue;
use SellerPortal\Domain\Shared\Reason;
use SellerPortal\Domain\Shared\UserId;
use stdClass;
use UnexpectedValueException;

/**
 * Repozytorium sprzedawców na Query Builderze (bez Eloquenta, ADR-0002).
 *
 * Bez outboxu: zdarzenia sprzedawcy nie wychodzą poza kontekst (reakcję
 * na blokadę realizuje polityka w procesie, a search dowiaduje się o skutkach
 * z `offer.suspended`). Optymistyczna blokada jak w `SqlOfferRepository`;
 * `save()` nie zmienia obiektu w pamięci, wołający przeładowuje agregat.
 */
final class SqlSellerRepository implements SellerRepository
{
    private const string USER_ID_INDEX = 'sellers_user_id_unique';

    public function nextIdentity(): SellerId
    {
        return SellerId::fromInt(NextId::for('sellers'));
    }

    public function get(SellerId $id): Seller
    {
        $row = DB::table('sellers')->where('id', $id->value())->first();

        if ($row === null) {
            throw new SellerNotFound;
        }

        return $this->hydrate($row);
    }

    public function findByUserId(UserId $userId): ?Seller
    {
        $row = DB::table('sellers')->where('user_id', $userId->value())->first();

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * DLACZEGO mapowanie naruszenia unikalności: dwie równoległe rejestracje
     * tego samego użytkownika mogą obie przejść sprawdzenie `findByUserId`;
     * indeks na `user_id` jest ostateczną gwarancją i ma dać czytelny błąd, nie 500.
     *
     * @throws SellerAlreadyRegistered
     */
    public function save(Seller $seller): void
    {
        try {
            $this->persist($seller);
        } catch (QueryException $e) {
            if ($this->violatesUserIdIndex($e)) {
                throw new SellerAlreadyRegistered;
            }

            throw $e;
        }
    }

    private function violatesUserIdIndex(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, self::USER_ID_INDEX)
            || str_contains($message, 'UNIQUE constraint failed: sellers.user_id');
    }

    private function persist(Seller $seller): void
    {
        DB::transaction(function () use ($seller): void {
            $newVersion = $seller->version() + 1;
            $now = SqlTimestamp::format(now()->toDateTimeImmutable());

            // DLACZEGO czas ostatniego zdarzenia, a nie zegar: zmiana statusu
            // i jej data pochodzą z tego samego momentu, który zna domena.
            $events = $seller->pendingEvents();
            $statusChangedAt = $events === [] ? null : SqlTimestamp::format($events[array_key_last($events)]->occurredAt());

            $state = [
                'name' => $seller->name()->value(),
                'city' => $seller->city()->value(),
                'status' => $seller->status()->value,
                'status_reason' => $seller->statusReason()?->value(),
                'version' => $newVersion,
                'updated_at' => $now,
            ];

            if ($seller->version() === 0) {
                $id = $seller->id()->value();

                DB::table('sellers')->insert($state + [
                    'id' => $id,
                    'user_id' => $seller->userId()?->value(),
                    // Slug z samej nazwy nie jest unikalny; sufiks z ID jest.
                    'slug' => Str::slug($seller->name()->value()).'-'.$id,
                    'rating' => 0,
                    'lat' => null,
                    'lon' => null,
                    'status_changed_at' => $statusChangedAt,
                    'created_at' => $now,
                ]);

                return;
            }

            if ($statusChangedAt !== null) {
                $state['status_changed_at'] = $statusChangedAt;
            }

            $updated = DB::table('sellers')
                ->where('id', $seller->id()->value())
                ->where('version', $seller->version())
                ->update($state);

            if ($updated === 0) {
                throw new ConcurrencyConflict;
            }
        });
    }

    private function hydrate(stdClass $row): Seller
    {
        $id = (int) $row->id;

        try {
            return Seller::reconstitute(
                SellerId::fromInt($id),
                $row->user_id === null ? null : UserId::fromInt((int) $row->user_id),
                SellerName::fromString((string) $row->name),
                City::fromString((string) $row->city),
                SellerStatus::tryFrom((string) $row->status) ?? throw new UnexpectedValueException("nieznany status '{$row->status}'"),
                $row->status_reason === null ? null : Reason::fromString($row->status_reason),
                (int) $row->version,
            );
        } catch (UnexpectedValueException|InvalidValue $e) {
            throw new UnexpectedValueException("Uszkodzony wiersz sprzedawcy #{$id}: {$e->getMessage()}", previous: $e);
        }
    }
}
