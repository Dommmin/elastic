<?php

namespace SellerPortal\Application\Port;

use SellerPortal\Domain\Offer\ProductId;

/**
 * Port do katalogu produktów, który należy do innego kontekstu: portal pyta
 * tylko "czy taki produkt istnieje", nie zna jego modelu.
 */
interface ProductCatalog
{
    public function exists(ProductId $productId): bool;
}
