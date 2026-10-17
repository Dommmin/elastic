<?php

namespace Tests\Unit\Ordering\InMemory;

use Ordering\Application\Port\SellerAccounts;
use Ordering\Domain\SellerId;

final class FakeSellerAccounts implements SellerAccounts
{
    /** @var array<int, SellerId> klucz = ID użytkownika */
    private array $sellers = [];

    public function register(int $userId, int $sellerId): void
    {
        $this->sellers[$userId] = SellerId::fromInt($sellerId);
    }

    public function sellerIdForUser(int $userId): ?SellerId
    {
        return $this->sellers[$userId] ?? null;
    }
}
