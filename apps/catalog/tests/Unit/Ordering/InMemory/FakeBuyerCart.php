<?php

namespace Tests\Unit\Ordering\InMemory;

use Ordering\Application\Port\BuyerCart;
use Ordering\Domain\BuyerId;

final class FakeBuyerCart implements BuyerCart
{
    /** @var list<BuyerId> */
    public array $cleared = [];

    public function clear(BuyerId $buyerId): void
    {
        $this->cleared[] = $buyerId;
    }
}
