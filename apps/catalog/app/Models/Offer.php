<?php

namespace App\Models;

use App\Concerns\EmitsOutboxEvents;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Offer extends Model
{
    use EmitsOutboxEvents;
    use HasFactory;

    protected $fillable = [
        'version',
        'product_id',
        'seller_id',
        'price_cents',
        'currency',
        'stock',
        'condition',
        'shipping_days',
        'status',
        'suspension_kind',
        'suspension_note',
        'suspended_by',
        'pending_price_cents',
        'pending_requested_at',
    ];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'stock' => 'integer',
            'shipping_days' => 'integer',
            'suspended_by' => 'integer',
            'pending_price_cents' => 'integer',
            'pending_requested_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /**
     * Jedyna definicja „widoczna w wyszukiwarce” po stronie odczytu; zgodna
     * z `Offer::isListed()` w domenie. Zastępuje dawne `where('active', true)`
     * w projekcji, panelu produktów i śladzie zdarzenia.
     */
    #[Scope]
    protected function listed(Builder $query): void
    {
        $query->where('status', 'published')->where('stock', '>', 0);
    }
}
