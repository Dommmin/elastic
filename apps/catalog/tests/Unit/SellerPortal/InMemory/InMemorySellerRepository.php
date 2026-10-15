<?php

namespace Tests\Unit\SellerPortal\InMemory;

use SellerPortal\Domain\Seller\Seller;
use SellerPortal\Domain\Seller\SellerId;
use SellerPortal\Domain\Seller\SellerNotFound;
use SellerPortal\Domain\Seller\SellerRepository;
use SellerPortal\Domain\Shared\ConcurrencyConflict;
use SellerPortal\Domain\Shared\UserId;

/**
 * Ten sam kontrakt co SqlSellerRepository: optymistyczna wersja (nowy
 * agregat ma 0, zapis daje version + 1) i kopie zamiast referencji, żeby
 * test nie zmienił zapisanego stanu bez `save()`.
 */
final class InMemorySellerRepository implements SellerRepository
{
    /** @var array<int, Seller> */
    private array $stored = [];

    private int $lastId = 0;

    public function nextIdentity(): SellerId
    {
        return SellerId::fromInt(++$this->lastId);
    }

    public function get(SellerId $id): Seller
    {
        return isset($this->stored[$id->value()]) ? $this->copy($this->stored[$id->value()]) : throw new SellerNotFound;
    }

    public function findByUserId(UserId $userId): ?Seller
    {
        foreach ($this->stored as $seller) {
            if ($seller->userId()?->equals($userId)) {
                return $this->copy($seller);
            }
        }

        return null;
    }

    public function save(Seller $seller): void
    {
        $current = $this->stored[$seller->id()->value()] ?? null;

        if (($current?->version() ?? 0) !== $seller->version()) {
            throw new ConcurrencyConflict;
        }

        $this->stored[$seller->id()->value()] = $this->copy($seller, $seller->version() + 1);
    }

    private function copy(Seller $seller, ?int $version = null): Seller
    {
        return Seller::reconstitute(
            $seller->id(),
            $seller->userId(),
            $seller->name(),
            $seller->city(),
            $seller->status(),
            $seller->statusReason(),
            $version ?? $seller->version(),
        );
    }
}
