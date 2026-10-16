<?php

namespace Tests\Unit\Ordering\InMemory;

use Ordering\Application\Port\BuyerCart;
use Ordering\Domain\BuyerId;
use Ordering\Domain\OfferId;

final class FakeBuyerCart implements BuyerCart
{
    /** @var list<array{buyerId: BuyerId, offerIds: list<OfferId>}> */
    public array $cleared = [];

    public function clear(BuyerId $buyerId, array $offerIds): void
    {
        $this->cleared[] = ['buyerId' => $buyerId, 'offerIds' => $offerIds];
    }
}
