<?php

namespace SellerPortal\Domain\Offer;

use SellerPortal\Domain\Shared\InvalidValue;
use SellerPortal\Domain\Shared\ModeratorId;
use SellerPortal\Domain\Shared\Reason;

/**
 * Powód zawieszenia oferty: rodzaj + (dla ręcznej moderacji) kto i dlaczego.
 *
 * DLACZEGO dwie fabryki zamiast publicznego konstruktora: dwa dozwolone
 * kształty (moderator z notatką albo automat po blokadzie sprzedawcy, bez
 * człowieka) — niedozwolona kombinacja, np. blokada z moderatorem, jest
 * po prostu nieosiągalna.
 */
final readonly class SuspensionReason
{
    private function __construct(
        private SuspensionKind $kind,
        private ?ModeratorId $moderatorId,
        private ?Reason $note,
    ) {}

    public static function byModerator(ModeratorId $moderatorId, Reason $note): self
    {
        return new self(SuspensionKind::Moderation, $moderatorId, $note);
    }

    public static function sellerBlocked(): self
    {
        return new self(SuspensionKind::SellerBlocked, null, null);
    }

    /**
     * Odtworzenie z bazy: kombinacja wynika z zapisanych kolumn, więc
     * niezmienniki fabryk powyżej trzeba tu powtórzyć — uszkodzony wiersz
     * (np. blokada z moderatorem) ma zgłosić błąd, nie stać się obiektem.
     *
     * @throws InvalidValue
     */
    public static function restore(SuspensionKind $kind, ?ModeratorId $moderatorId, ?Reason $note): self
    {
        $valid = match ($kind) {
            SuspensionKind::SellerBlocked => $moderatorId === null && $note === null,
            SuspensionKind::Moderation => $moderatorId !== null && $note !== null,
        };

        if (! $valid) {
            throw new InvalidValue('Niespójny powód zawieszenia: rodzaj nie pasuje do moderatora i notatki.');
        }

        return new self($kind, $moderatorId, $note);
    }

    public function kind(): SuspensionKind
    {
        return $this->kind;
    }

    public function note(): ?Reason
    {
        return $this->note;
    }

    public function moderatorId(): ?ModeratorId
    {
        return $this->moderatorId;
    }
}
