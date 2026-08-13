<?php

namespace App\Models;

use App\Concerns\EmitsOutboxEvents;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use EmitsOutboxEvents;
    use HasFactory;

    protected $fillable = [
        'version',
        'brand_id',
        'category_id',
        'name',
        'description',
        'attributes',
        'ean',
    ];

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'version' => 'integer',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function activeOffers(): HasMany
    {
        return $this->offers()->where('active', true);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }
}
