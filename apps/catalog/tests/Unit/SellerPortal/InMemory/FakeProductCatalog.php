<?php

namespace Tests\Unit\SellerPortal\InMemory;

use SellerPortal\Application\Port\ProductCatalog;
use SellerPortal\Domain\Offer\ProductId;

final class FakeProductCatalog implements ProductCatalog
{
    /**
     * @param  list<int>  $existing
     */
    public function __construct(private array $existing = [1, 2, 3]) {}

    public function exists(ProductId $productId): bool
    {
        return in_array($productId->value(), $this->existing, true);
    }
}
