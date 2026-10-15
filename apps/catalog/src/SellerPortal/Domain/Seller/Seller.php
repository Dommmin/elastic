<?php

namespace SellerPortal\Domain\Seller;

use DateTimeImmutable;
use SellerPortal\Domain\Seller\Event\SellerApproved;
use SellerPortal\Domain\Seller\Event\SellerBlocked;
use SellerPortal\Domain\Seller\Event\SellerRegistered;
use SellerPortal\Domain\Seller\Event\SellerRejected;
use SellerPortal\Domain\Seller\Event\SellerUnblocked;
use SellerPortal\Domain\Shared\AggregateRoot;
use SellerPortal\Domain\Shared\ModeratorId;
use SellerPortal\Domain\Shared\Reason;
use SellerPortal\Domain\Shared\UserId;

/**
 * Agregat sprzedawcy: konto handlowe użytkownika i jego status w moderacji.
 *
 * DLACZEGO zmiany stanu to metody domenowe (`approve`, `block`…), a nie
 * settery: każda sprawdza, czy przejście jest dozwolone, i zapisuje
 * zdarzenie. Dzięki temu nie da się ominąć reguł zmieniając pole z zewnątrz.
 *
 * Maszyna stanów:
 *   Pending --approve--> Active --block--> Blocked --unblock--> Active
 *   Pending --reject---> Rejected (koniec)
 *
 * DLACZEGO `version` jest tylko odczytywana: numer wersji należy do
 * optymistycznej blokady w repozytorium, domena go nie zwiększa.
 * Świeżo zarejestrowany sprzedawca ma wersję 0 — po tym repozytorium
 * rozpoznaje, że trzeba zrobić INSERT, a nie UPDATE.
 *
 * `userId` jest nullable tylko przy odtwarzaniu: konto użytkownika mogło
 * zniknąć z bazy, a sprzedawca (z historią ofert) ma przetrwać.
 */
final class Seller extends AggregateRoot
{
    private function __construct(
        private readonly SellerId $id,
        private ?UserId $userId,
        private SellerName $name,
        private City $city,
        private SellerStatus $status,
        private ?Reason $statusReason,
        private int $version,
    ) {}

    public static function register(
        SellerId $id,
        UserId $userId,
        SellerName $name,
        City $city,
        DateTimeImmutable $now,
    ): self {
        $seller = new self($id, $userId, $name, $city, SellerStatus::Pending, null, 0);
        $seller->recordThat(new SellerRegistered($id, $userId, $now));

        return $seller;
    }

    /**
     * Odtworzenie z bazy: bez zdarzeń, bo nic się nie wydarzyło — tylko
     * wczytujemy stan, który już raz został zdarzeniami opisany.
     */
    public static function reconstitute(
        SellerId $id,
        ?UserId $userId,
        SellerName $name,
        City $city,
        SellerStatus $status,
        ?Reason $statusReason,
        int $version,
    ): self {
        return new self($id, $userId, $name, $city, $status, $statusReason, $version);
    }

    public function approve(ModeratorId $moderator, DateTimeImmutable $now): void
    {
        $this->requireStatus(SellerStatus::Pending, 'approve');

        $this->status = SellerStatus::Active;
        $this->statusReason = null;
        $this->recordThat(new SellerApproved($this->id, $moderator, $now));
    }

    public function reject(ModeratorId $moderator, Reason $reason, DateTimeImmutable $now): void
    {
        $this->requireStatus(SellerStatus::Pending, 'reject');

        $this->status = SellerStatus::Rejected;
        $this->statusReason = $reason;
        $this->recordThat(new SellerRejected($this->id, $moderator, $reason, $now));
    }

    public function block(ModeratorId $moderator, Reason $reason, DateTimeImmutable $now): void
    {
        $this->requireStatus(SellerStatus::Active, 'block');

        $this->status = SellerStatus::Blocked;
        $this->statusReason = $reason;
        $this->recordThat(new SellerBlocked($this->id, $moderator, $reason, $now));
    }

    public function unblock(ModeratorId $moderator, DateTimeImmutable $now): void
    {
        $this->requireStatus(SellerStatus::Blocked, 'unblock');

        $this->status = SellerStatus::Active;
        $this->statusReason = null;
        $this->recordThat(new SellerUnblocked($this->id, $moderator, $now));
    }

    /**
     * DLACZEGO pytanie, a nie wyjątek: wywołujący (np. wystawianie oferty)
     * sam zdecyduje, czy rzucić `SellerCannotSell`, czy pokazać komunikat.
     */
    public function canSell(): bool
    {
        return $this->status === SellerStatus::Active;
    }

    public function id(): SellerId
    {
        return $this->id;
    }

    public function userId(): ?UserId
    {
        return $this->userId;
    }

    public function name(): SellerName
    {
        return $this->name;
    }

    public function city(): City
    {
        return $this->city;
    }

    public function status(): SellerStatus
    {
        return $this->status;
    }

    public function statusReason(): ?Reason
    {
        return $this->statusReason;
    }

    public function version(): int
    {
        return $this->version;
    }

    private function requireStatus(SellerStatus $expected, string $action): void
    {
        if ($this->status !== $expected) {
            throw InvalidSellerTransition::from($this->status, $action);
        }
    }
}
