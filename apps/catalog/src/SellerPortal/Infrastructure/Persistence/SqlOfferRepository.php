<?php

namespace SellerPortal\Infrastructure\Persistence;

use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use SellerPortal\Application\Port\Clock;
use SellerPortal\Domain\Offer\Condition;
use SellerPortal\Domain\Offer\LiveOfferAlreadyExists;
use SellerPortal\Domain\Offer\Offer;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Offer\OfferNotFound;
use SellerPortal\Domain\Offer\OfferRepository;
use SellerPortal\Domain\Offer\OfferStatus;
use SellerPortal\Domain\Offer\PendingPriceChange;
use SellerPortal\Domain\Offer\ProductId;
use SellerPortal\Domain\Offer\ShippingTime;
use SellerPortal\Domain\Offer\Stock;
use SellerPortal\Domain\Offer\SuspensionKind;
use SellerPortal\Domain\Offer\SuspensionReason;
use SellerPortal\Domain\Seller\SellerId;
use SellerPortal\Domain\Shared\ModeratorId;
use SellerPortal\Domain\Shared\Reason;
use SharedKernel\ConcurrencyConflict;
use SharedKernel\Currency;
use SharedKernel\InvalidValue;
use SharedKernel\Money;
use stdClass;
use UnexpectedValueException;

/**
 * Repozytorium ofert na Query Builderze (bez Eloquenta, ADR-0002): agregat
 * ma prywatny konstruktor i niezmienniki, więc mapujemy go ręcznie.
 *
 * DLACZEGO optymistyczna blokada przez `UPDATE ... WHERE version = ?`:
 * dwa żądania (sprzedawca, moderator, job polityki) mogą zapisać tę samą
 * ofertę równocześnie — między odczytem a zapisem jednego wchodzi drugi.
 * Wygrywa pierwszy zapis; drugi trafia w 0 wierszy i dostaje
 * `ConcurrencyConflict` zamiast po cichu nadpisać cudzą zmianę.
 *
 * To łapie tylko żądania NAKŁADAJĄCE SIĘ w czasie. Dwie karty otwarte od
 * kwadransa się nie nakładają: druga wczytuje świeży agregat. Ten przypadek
 * pokrywa wersja odsyłana z formularza i sprawdzana w handlerze
 * (`SellerPortal\Application\ExpectedVersion`) — oba mechanizmy rzucają
 * ten sam wyjątek, więc UI pokazuje ten sam komunikat.
 *
 * `save()` NIE zmienia obiektu w pamięci: po zapisie baza ma `version + 1`,
 * a agregat dalej trzyma starą wersję i zdarzenia. Wołający (handler:
 * wczytaj → zmień → zapisz, raz na transakcję) przeładowuje agregat, jeśli
 * go jeszcze potrzebuje. Zdarzenia zwalnia (`releaseEvents`) dopiero handler.
 */
final class SqlOfferRepository implements OfferRepository
{
    private const string LIVE_OFFER_INDEX = 'offers_one_live_per_seller_product';

    public function __construct(private readonly Clock $clock) {}

    public function nextIdentity(): OfferId
    {
        return OfferId::fromInt(NextId::for('offers'));
    }

    public function get(OfferId $id): Offer
    {
        $row = DB::table('offers')->where('id', $id->value())->first();

        if ($row === null) {
            throw new OfferNotFound;
        }

        // DLACZEGO tylko ostatnie 24 h: limit dobowy nie potrzebuje starszej historii.
        $since = $this->clock->now()->sub(new DateInterval('PT24H'));

        $changes = DB::table('offer_price_changes')
            ->where('offer_id', $id->value())
            ->where('changed_at', '>=', SqlTimestamp::format($since))
            ->orderBy('changed_at')
            ->pluck('changed_at')
            ->map(fn (string $value): DateTimeImmutable => SqlTimestamp::parse($value))
            ->all();

        return $this->hydrate($row, array_values($changes));
    }

    public function save(Offer $offer): void
    {
        try {
            DB::transaction(function () use ($offer): void {
                $newVersion = $offer->version() + 1;

                if ($offer->version() === 0) {
                    DB::table('offers')->insert($this->row($offer) + [
                        'id' => $offer->id()->value(),
                        'product_id' => $offer->productId()->value(),
                        'seller_id' => $offer->sellerId()->value(),
                        'version' => $newVersion,
                        'created_at' => SqlTimestamp::format($this->clock->now()),
                        'updated_at' => SqlTimestamp::format($this->clock->now()),
                    ]);
                } else {
                    $updated = DB::table('offers')
                        ->where('id', $offer->id()->value())
                        ->where('version', $offer->version())
                        ->update($this->row($offer) + [
                            'version' => $newVersion,
                            'updated_at' => SqlTimestamp::format($this->clock->now()),
                        ]);

                    if ($updated === 0) {
                        throw new ConcurrencyConflict;
                    }
                }

                $this->savePriceChanges($offer);
                OfferIntegrationEvents::record($offer, $offer->pendingEvents(), $newVersion);
            });
        } catch (QueryException $e) {
            if ($this->violatesLiveOfferIndex($e)) {
                throw new LiveOfferAlreadyExists;
            }

            throw $e;
        }
    }

    public function hasLiveOffer(SellerId $sellerId, ProductId $productId): bool
    {
        return DB::table('offers')
            ->where('seller_id', $sellerId->value())
            ->where('product_id', $productId->value())
            ->where('status', '<>', OfferStatus::Withdrawn->value)
            ->exists();
    }

    public function idsForSellerPolicy(SellerId $sellerId, OfferStatus $status, ?SuspensionKind $suspensionKind, int $afterId, int $limit): array
    {
        $ids = DB::table('offers')
            ->where('seller_id', $sellerId->value())
            ->where('status', $status->value)
            ->when($suspensionKind !== null, fn ($query) => $query->where('suspension_kind', $suspensionKind->value))
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        return $ids->map(fn ($id): OfferId => OfferId::fromInt((int) $id))->all();
    }

    /**
     * Kolumny zmienne w czasie życia oferty (tożsamość, produkt i sprzedawca
     * są stałe i dochodzą tylko przy INSERT).
     *
     * @return array<string, mixed>
     */
    private function row(Offer $offer): array
    {
        $suspension = $offer->suspension();
        $pending = $offer->pendingPriceChange();

        return [
            'price_cents' => $offer->price()->cents(),
            'currency' => $offer->price()->currency()->value,
            'stock' => $offer->stock()->value(),
            'condition' => $offer->condition()->value,
            'shipping_days' => $offer->shippingTime()->value(),
            'status' => $offer->status()->value,
            'suspension_kind' => $suspension?->kind()->value,
            'suspension_note' => $suspension?->note()?->value(),
            'suspended_by' => $suspension?->moderatorId()?->value(),
            'pending_price_cents' => $pending?->price()->cents(),
            'pending_requested_at' => $pending === null ? null : SqlTimestamp::format($pending->requestedAt()),
        ];
    }

    /**
     * DLACZEGO dopisujemy tylko wpisy nowsze od najnowszego zapisanego:
     * agregat zna jedynie okno 24 h, więc starsze wpisy w bazie ani nie
     * trafiają do niego, ani nie mogą zostać zdublowane.
     */
    private function savePriceChanges(Offer $offer): void
    {
        $latest = DB::table('offer_price_changes')->where('offer_id', $offer->id()->value())->max('changed_at');
        $latest = $latest === null ? null : SqlTimestamp::parse($latest);

        foreach ($offer->recentPriceChanges() as $changedAt) {
            if ($latest !== null && $changedAt <= $latest) {
                continue;
            }

            DB::table('offer_price_changes')->insert([
                'offer_id' => $offer->id()->value(),
                'changed_at' => SqlTimestamp::format($changedAt),
            ]);
        }
    }

    /**
     * Postgres nazywa naruszony indeks w komunikacie; SQLite podaje kolumny.
     */
    private function violatesLiveOfferIndex(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, self::LIVE_OFFER_INDEX)
            || str_contains($message, 'UNIQUE constraint failed: offers.seller_id, offers.product_id');
    }

    /**
     * @param  list<DateTimeImmutable>  $recentPriceChanges
     */
    private function hydrate(stdClass $row, array $recentPriceChanges): Offer
    {
        $id = (int) $row->id;

        try {
            $status = OfferStatus::tryFrom((string) $row->status)
                ?? throw new UnexpectedValueException("nieznany status '{$row->status}'");
            $currency = Currency::tryFrom((string) $row->currency)
                ?? throw new UnexpectedValueException("nieznana waluta '{$row->currency}'");
            $condition = Condition::tryFrom((string) $row->condition)
                ?? throw new UnexpectedValueException("nieznany stan '{$row->condition}'");

            if (($status === OfferStatus::Suspended) !== ($row->suspension_kind !== null)) {
                throw new UnexpectedValueException("status '{$status->value}' nie pasuje do suspension_kind");
            }

            if (($row->pending_price_cents === null) !== ($row->pending_requested_at === null)) {
                throw new UnexpectedValueException('oczekująca cena i data zgłoszenia muszą występować razem');
            }

            $suspension = null;
            if ($row->suspension_kind !== null) {
                $kind = SuspensionKind::tryFrom((string) $row->suspension_kind)
                    ?? throw new UnexpectedValueException("nieznany rodzaj zawieszenia '{$row->suspension_kind}'");
                $suspension = SuspensionReason::restore(
                    $kind,
                    $row->suspended_by === null ? null : ModeratorId::fromInt((int) $row->suspended_by),
                    $row->suspension_note === null ? null : Reason::fromString($row->suspension_note),
                );
            }

            $pending = $row->pending_price_cents === null
                ? null
                : new PendingPriceChange(Money::fromCents((int) $row->pending_price_cents, $currency), SqlTimestamp::parse($row->pending_requested_at));

            return Offer::reconstitute(
                OfferId::fromInt($id),
                SellerId::fromInt((int) $row->seller_id),
                ProductId::fromInt((int) $row->product_id),
                Money::fromCents((int) $row->price_cents, $currency),
                Stock::fromInt((int) $row->stock),
                $condition,
                ShippingTime::fromInt((int) $row->shipping_days),
                $status,
                $suspension,
                $pending,
                $recentPriceChanges,
                (int) $row->version,
            );
        } catch (UnexpectedValueException|InvalidValue $e) {
            throw new UnexpectedValueException("Uszkodzony wiersz oferty #{$id}: {$e->getMessage()}", previous: $e);
        }
    }
}
