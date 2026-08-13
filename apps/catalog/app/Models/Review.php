<?php

namespace App\Models;

use App\Concerns\EmitsOutboxEvents;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    use EmitsOutboxEvents;
    use HasFactory;

    protected $fillable = ['product_id', 'user_id', 'rating', 'body'];

    protected function casts(): array
    {
        return ['rating' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Opinie są create-only w katalogu zdarzeń (docs/02-APLIKACJE.md) —
     * nie ma "review.updated", więc nie ma czego chronić wersjonowaniem.
     */
    protected function usesOutboxVersioning(): bool
    {
        return false;
    }
}
