<?php

namespace SellerPortal\Domain\Offer;

use DateTimeImmutable;
use SellerPortal\Domain\Offer\Event\OfferBackInStock;
use SellerPortal\Domain\Offer\Event\OfferDetailsChanged;
use SellerPortal\Domain\Offer\Event\OfferDrafted;
use SellerPortal\Domain\Offer\Event\OfferPriceChangeApproved;
use SellerPortal\Domain\Offer\Event\OfferPriceChangeCancelled;
use SellerPortal\Domain\Offer\Event\OfferPriceChanged;
use SellerPortal\Domain\Offer\Event\OfferPriceChangeRejected;
use SellerPortal\Domain\Offer\Event\OfferPriceChangeRequested;
use SellerPortal\Domain\Offer\Event\OfferPublished;
use SellerPortal\Domain\Offer\Event\OfferReinstated;
use SellerPortal\Domain\Offer\Event\OfferSoldOut;
use SellerPortal\Domain\Offer\Event\OfferStockChanged;
use SellerPortal\Domain\Offer\Event\OfferSuspended;
use SellerPortal\Domain\Offer\Event\OfferWithdrawn;
use SellerPortal\Domain\Seller\Seller;
use SellerPortal\Domain\Seller\SellerCannotSell;
use SellerPortal\Domain\Seller\SellerId;
use SellerPortal\Domain\Shared\AggregateRoot;
use SellerPortal\Domain\Shared\CurrencyMismatch;
use SellerPortal\Domain\Shared\ModeratorId;
use SellerPortal\Domain\Shared\Money;
use SellerPortal\Domain\Shared\Reason;

/**
 * Agregat oferty: propozycja sprzedaży jednego produktu przez jednego sprzedawcę.
 *
 * Maszyna stanów:
 *   Draft --publish--> Published --suspend--> Suspended --reinstate--> Published
 *   Draft | Published | Suspended --withdraw--> Withdrawn (koniec)
 *
 * DLACZEGO "wyprzedana" NIE jest statusem: to pochodna `status` i `stock`
 * (`isSoldOut`). Gdyby była osobnym statusem, trzeba by go ręcznie
 * synchronizować ze stanem magazynowym przy każdej zmianie i każdym
 * zawieszeniu — a tak dwie prawdy rozjechać się nie mogą.
 *
 * DLACZEGO sprzedawca jest tylko ID: Offer i Seller to osobne agregaty,
 * które łączymy identyfikatorem. Dwa momenty, w których oferta potrzebuje
 * wiedzy o sprzedawcy (publikacja i przywrócenie po zawieszeniu), dostają
 * go jako argument `publish` / `reinstate`.
 *
 * DLACZEGO `version` jest tylko odczytywana: należy do optymistycznej
 * blokady w repozytorium. Nowa oferta ma wersję 0 — po tym repozytorium
 * rozpoznaje INSERT (tak samo jak przy `Seller::register`).
 */
final class Offer extends AggregateRoot
{
    /**
     * @param  list<DateTimeImmutable>  $recentPriceChanges
     */
    private function __construct(
        private readonly OfferId $id,
        private readonly SellerId $sellerId,
        private readonly ProductId $productId,
        private Money $price,
        private Stock $stock,
        private Condition $condition,
        private ShippingTime $shippingTime,
        private OfferStatus $status,
        private ?SuspensionReason $suspension,
        private ?PendingPriceChange $pendingPriceChange,
        private array $recentPriceChanges,
        private int $version,
    ) {}

    /**
     * Woła się przez `Seller::draftOffer`, nie bezpośrednio: tylko sprzedawca
     * wie, czy w ogóle wolno mu wystawiać oferty.
     */
    public static function draft(
        OfferId $id,
        SellerId $sellerId,
        ProductId $productId,
        Money $price,
        Stock $stock,
        Condition $condition,
        ShippingTime $shippingTime,
        DateTimeImmutable $now,
    ): self {
        $offer = new self($id, $sellerId, $productId, $price, $stock, $condition, $shippingTime, OfferStatus::Draft, null, null, [], 0);
        $offer->recordThat(new OfferDrafted($id, $sellerId, $productId, $now));

        return $offer;
    }

    /**
     * Odtworzenie z bazy: bez zdarzeń, bo tylko wczytujemy stan, który już
     * raz został opisany zdarzeniami.
     *
     * @param  list<DateTimeImmutable>  $recentPriceChanges
     */
    public static function reconstitute(
        OfferId $id,
        SellerId $sellerId,
        ProductId $productId,
        Money $price,
        Stock $stock,
        Condition $condition,
        ShippingTime $shippingTime,
        OfferStatus $status,
        ?SuspensionReason $suspension,
        ?PendingPriceChange $pendingPriceChange,
        array $recentPriceChanges,
        int $version,
    ): self {
        return new self($id, $sellerId, $productId, $price, $stock, $condition, $shippingTime, $status, $suspension, $pendingPriceChange, $recentPriceChanges, $version);
    }

    /**
     * @throws InvalidOfferTransition
     * @throws SellerCannotSell
     * @throws OfferCannotBePublishedWithoutStock
     */
    public function publish(Seller $seller, DateTimeImmutable $now): void
    {
        $this->requireStatus([OfferStatus::Draft], OfferAction::Publish);

        if (! $seller->canSell()) {
            throw new SellerCannotSell;
        }

        if ($this->stock->value() === 0) {
            throw new OfferCannotBePublishedWithoutStock;
        }

        $this->status = OfferStatus::Published;
        $this->recordThat(new OfferPublished($this->id, $now));
    }

    /**
     * DLACZEGO przejście przez zero to osobne zdarzenie TYLKO na
     * opublikowanej ofercie: dopiero wtedy zmienia się to, co widzi kupujący
     * (oferta znika z półki albo na nią wraca). W szkicu i przy zawieszeniu
     * oferta i tak nie jest widoczna, więc to zwykła zmiana liczby.
     * Jedna zmiana = jedno zdarzenie; ten sam stan nie robi nic.
     */
    public function changeStock(Stock $stock, DateTimeImmutable $now): void
    {
        $this->requireStatus([OfferStatus::Draft, OfferStatus::Published, OfferStatus::Suspended], OfferAction::ChangeStock);

        $old = $this->stock;

        if ($old->equals($stock)) {
            return;
        }

        $this->stock = $stock;

        if ($this->status === OfferStatus::Published && $old->value() > 0 && $stock->value() === 0) {
            $this->recordThat(new OfferSoldOut($this->id, $old, $now));
        } elseif ($this->status === OfferStatus::Published && $old->value() === 0) {
            $this->recordThat(new OfferBackInStock($this->id, $stock, $now));
        } else {
            $this->recordThat(new OfferStockChanged($this->id, $old, $stock, $now));
        }
    }

    public function changeDetails(Condition $condition, ShippingTime $shippingTime, DateTimeImmutable $now): void
    {
        $this->requireStatus([OfferStatus::Draft, OfferStatus::Published], OfferAction::ChangeDetails);

        $this->condition = $condition;
        $this->shippingTime = $shippingTime;
        $this->recordThat(new OfferDetailsChanged($this->id, $condition, $shippingTime, $now));
    }

    public function suspend(SuspensionReason $reason, DateTimeImmutable $now): void
    {
        $this->requireStatus([OfferStatus::Published], OfferAction::Suspend);

        $this->status = OfferStatus::Suspended;
        $this->suspension = $reason;
        $this->recordThat(new OfferSuspended($this->id, $reason, $now));
    }

    /**
     * DLACZEGO przywrócenie wymaga sprzedawcy, jak publikacja: oba przejścia
     * wystawiają ofertę kupującym, a „zablokowany sprzedawca nie sprzedaje”.
     * Bez tego moderator jednym kliknięciem „Przywróć” wracał do wyszukiwarki
     * ofertę konta, które wciąż jest zablokowane.
     *
     * @throws InvalidOfferTransition
     * @throws SellerCannotSell
     */
    public function reinstate(Seller $seller, DateTimeImmutable $now): void
    {
        $this->requireStatus([OfferStatus::Suspended], OfferAction::Reinstate);

        if (! $seller->canSell()) {
            throw new SellerCannotSell;
        }

        $this->status = OfferStatus::Published;
        $this->suspension = null;
        $this->recordThat(new OfferReinstated($this->id, $now));
    }

    /**
     * DLACZEGO wycofanie kasuje też oczekującą zmianę ceny: wycofana oferta
     * jest stanem końcowym, a wisząca w kolejce moderatora prośba o cenę
     * dotyczyłaby oferty, której nikt już nie kupi.
     */
    public function withdraw(DateTimeImmutable $now): void
    {
        $this->requireStatus([OfferStatus::Draft, OfferStatus::Published, OfferStatus::Suspended], OfferAction::Withdraw);

        $this->status = OfferStatus::Withdrawn;
        $this->pendingPriceChange = null;
        $this->recordThat(new OfferWithdrawn($this->id, $now));
    }

    /**
     * Strażnik ceny. W szkicu cena zmienia się od razu: nikt jej jeszcze nie
     * widzi, więc nie ma przed czym chronić kupujących (bez limitu, bez
     * wpisu do okna). W opublikowanej ofercie kolejność jest stała:
     * oczekująca zmiana → limit → próg.
     *
     * DLACZEGO i wprowadzona, i zgłoszona zmiana trafia do `recentPriceChanges`:
     * limit ma ograniczać klikanie sprzedawcy, a nie to, ile przeszło bez
     * moderatora. Akceptacja moderatora już nie dopisuje — to nie jest jego akcja.
     * Ta sama cena to no-op (jak ten sam stan magazynowy): bez zdarzenia i bez
     * zużywania limitu.
     *
     * @throws InvalidOfferTransition
     * @throws PriceChangeAlreadyPending
     * @throws PriceChangeLimitExceeded
     * @throws CurrencyMismatch
     */
    public function changePrice(Money $proposed, DateTimeImmutable $now): PriceChangeDecision
    {
        $this->requireStatus([OfferStatus::Draft, OfferStatus::Published], OfferAction::ChangePrice);

        $current = $this->price;

        if ($this->status === OfferStatus::Draft) {
            if ($current->currency() !== $proposed->currency()) {
                throw CurrencyMismatch::between($current->currency(), $proposed->currency());
            }

            if (! $current->equals($proposed)) {
                $this->price = $proposed;
                $this->recordThat(new OfferPriceChanged($this->id, $current, $proposed, $now));
            }

            return PriceChangeDecision::AppliedNow;
        }

        if ($this->pendingPriceChange !== null) {
            throw new PriceChangeAlreadyPending;
        }

        if ($current->equals($proposed)) {
            return PriceChangeDecision::AppliedNow;
        }

        $decision = PriceChangePolicy::decide($current, $proposed, $this->recentPriceChanges, $now);

        // Przycinamy do okna przy każdym dopisaniu, żeby lista nie rosła w nieskończoność.
        $this->recentPriceChanges = [...PriceChangePolicy::withinWindow($this->recentPriceChanges, $now), $now];

        if ($decision === PriceChangeDecision::AppliedNow) {
            $this->price = $proposed;
            $this->recordThat(new OfferPriceChanged($this->id, $current, $proposed, $now));
        } else {
            $this->pendingPriceChange = new PendingPriceChange($proposed, $now);
            $this->recordThat(new OfferPriceChangeRequested($this->id, $current, $proposed, $now));
        }

        return $decision;
    }

    /**
     * Sprzedawca wycofuje własną propozycję. Nie zwraca wpisu do limitu:
     * samo zgłoszenie było już "użyciem" zmiany.
     *
     * @throws InvalidOfferTransition
     * @throws NoPendingPriceChange
     */
    public function cancelPendingPriceChange(DateTimeImmutable $now): void
    {
        $pending = $this->requirePendingPriceChange(OfferAction::CancelPendingPriceChange);

        $this->pendingPriceChange = null;
        $this->recordThat(new OfferPriceChangeCancelled($this->id, $pending->price(), $now));
    }

    /**
     * DLACZEGO dozwolone także w Suspended: zawieszenie nie unieważnia
     * zgłoszenia, a moderator ma prawo je rozpatrzyć (zob. `allowedActionsFor`).
     *
     * DLACZEGO `$expectedPrice`: moderator akceptuje kwotę, którą zobaczył
     * w kolejce. Jeśli sprzedawca w międzyczasie anulował zgłoszenie i zgłosił
     * inne, decyzja dotyczy już czegoś innego (zob. `PendingPriceChangeMismatch`).
     *
     * @throws InvalidOfferTransition
     * @throws NoPendingPriceChange
     * @throws PendingPriceChangeMismatch
     */
    public function approvePriceChange(ModeratorId $moderatorId, Money $expectedPrice, DateTimeImmutable $now): void
    {
        $pending = $this->requirePendingPriceChange(OfferAction::ApprovePriceChange, $expectedPrice);

        $old = $this->price;
        $this->price = $pending->price();
        $this->pendingPriceChange = null;
        $this->recordThat(new OfferPriceChangeApproved($this->id, $old, $this->price, $moderatorId, $now));
    }

    /**
     * `$expectedPrice` z tego samego powodu co przy akceptacji: odrzucenie
     * z powodem „za drogo” nie może trafić w zgłoszenie, którego moderator nie widział.
     *
     * @throws InvalidOfferTransition
     * @throws NoPendingPriceChange
     * @throws PendingPriceChangeMismatch
     */
    public function rejectPriceChange(ModeratorId $moderatorId, Money $expectedPrice, Reason $reason, DateTimeImmutable $now): void
    {
        $pending = $this->requirePendingPriceChange(OfferAction::RejectPriceChange, $expectedPrice);

        $this->pendingPriceChange = null;
        $this->recordThat(new OfferPriceChangeRejected($this->id, $pending->price(), $moderatorId, $reason, $now));
    }

    /**
     * Oferta widoczna dla kupujących: opublikowana i jest co kupić.
     */
    public function isListed(): bool
    {
        return $this->status === OfferStatus::Published && $this->stock->value() > 0;
    }

    public function isSoldOut(): bool
    {
        return $this->status === OfferStatus::Published && $this->stock->value() === 0;
    }

    /**
     * Jedyne źródło prawdy dla UI: co wolno zrobić z tą ofertą temu aktorowi.
     * Przyciski w interfejsie powstają z tej listy, więc reguły nie
     * rozjeżdżają się między domeną a widokiem. Kolejność = kolejność
     * przypadków `OfferAction`.
     *
     * Lista zna tylko stan OFERTY. Reguły zależne od sprzedawcy (zablokowany
     * nie zmienia cen, jego ofert nie przywracamy) dokłada warstwa aplikacji
     * (`SellerOfferAccess::sellerActions` / `moderatorActions`), bo oferta
     * trzyma tylko ID sprzedawcy.
     *
     * @return list<OfferAction>
     */
    public function allowedActionsFor(Actor $actor): array
    {
        $pending = $this->pendingPriceChange !== null;

        $allowed = match ($actor) {
            Actor::Seller => match ($this->status) {
                OfferStatus::Draft => [
                    OfferAction::Publish->value => $this->stock->value() > 0,
                    OfferAction::ChangePrice->value => true,
                    OfferAction::ChangeStock->value => true,
                    OfferAction::ChangeDetails->value => true,
                    OfferAction::Withdraw->value => true,
                ],
                OfferStatus::Published => [
                    OfferAction::ChangePrice->value => ! $pending,
                    OfferAction::CancelPendingPriceChange->value => $pending,
                    OfferAction::ChangeStock->value => true,
                    OfferAction::ChangeDetails->value => true,
                    OfferAction::Withdraw->value => true,
                ],
                OfferStatus::Suspended => [
                    OfferAction::CancelPendingPriceChange->value => $pending,
                    OfferAction::ChangeStock->value => true,
                    OfferAction::Withdraw->value => true,
                ],
                OfferStatus::Withdrawn => [],
            },
            Actor::Moderator => match ($this->status) {
                OfferStatus::Published => [
                    OfferAction::Suspend->value => true,
                    OfferAction::ApprovePriceChange->value => $pending,
                    OfferAction::RejectPriceChange->value => $pending,
                ],
                OfferStatus::Suspended => [
                    OfferAction::Reinstate->value => true,
                    OfferAction::ApprovePriceChange->value => $pending,
                    OfferAction::RejectPriceChange->value => $pending,
                ],
                OfferStatus::Draft, OfferStatus::Withdrawn => [],
            },
        };

        // Kolejność wg enuma, niezależnie od kolejności zapisu w tabelach powyżej.
        return array_values(array_filter(
            OfferAction::cases(),
            static fn (OfferAction $action): bool => $allowed[$action->value] ?? false,
        ));
    }

    public function id(): OfferId
    {
        return $this->id;
    }

    public function sellerId(): SellerId
    {
        return $this->sellerId;
    }

    public function productId(): ProductId
    {
        return $this->productId;
    }

    public function price(): Money
    {
        return $this->price;
    }

    public function stock(): Stock
    {
        return $this->stock;
    }

    public function condition(): Condition
    {
        return $this->condition;
    }

    public function shippingTime(): ShippingTime
    {
        return $this->shippingTime;
    }

    public function status(): OfferStatus
    {
        return $this->status;
    }

    public function suspension(): ?SuspensionReason
    {
        return $this->suspension;
    }

    public function pendingPriceChange(): ?PendingPriceChange
    {
        return $this->pendingPriceChange;
    }

    /**
     * @return list<DateTimeImmutable>
     */
    public function recentPriceChanges(): array
    {
        return $this->recentPriceChanges;
    }

    public function version(): int
    {
        return $this->version;
    }

    private function requirePendingPriceChange(OfferAction $action, ?Money $expectedPrice = null): PendingPriceChange
    {
        $this->requireStatus([OfferStatus::Published, OfferStatus::Suspended], $action);

        $pending = $this->pendingPriceChange ?? throw new NoPendingPriceChange;

        if ($expectedPrice !== null && ! $pending->price()->equals($expectedPrice)) {
            throw new PendingPriceChangeMismatch;
        }

        return $pending;
    }

    /**
     * @param  list<OfferStatus>  $allowed
     */
    private function requireStatus(array $allowed, OfferAction $action): void
    {
        if (! in_array($this->status, $allowed, true)) {
            throw InvalidOfferTransition::from($this->status, $action);
        }
    }
}
