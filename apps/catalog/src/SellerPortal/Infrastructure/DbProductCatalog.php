<?php

namespace SellerPortal\Infrastructure;

use Illuminate\Support\Facades\DB;
use SellerPortal\Application\Port\ProductCatalog;
use SellerPortal\Domain\Offer\ProductId;

/**
 * Katalog produktów to tabela `products` z innego kontekstu: pytamy o samo
 * istnienie wiersza, bez ładowania modelu Eloquenta.
 */
final class DbProductCatalog implements ProductCatalog
{
    public function exists(ProductId $productId): bool
    {
        return DB::table('products')->where('id', $productId->value())->exists();
    }
}
