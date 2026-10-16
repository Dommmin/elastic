<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pozycja koszyka kupującego. Koszyk nie ma agregatu: to zwykłe dane
 * robocze UI, a reguły zamówienia sprawdza dopiero handler składania.
 */
class CartItem extends Model
{
    protected $fillable = ['user_id', 'offer_id', 'quantity', 'added_price_cents'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'offer_id' => 'integer',
            'quantity' => 'integer',
            'added_price_cents' => 'integer',
        ];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}
