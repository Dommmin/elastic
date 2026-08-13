<?php

namespace App\Models;

use App\Concerns\EmitsOutboxEvents;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Seller extends Model
{
    use EmitsOutboxEvents;
    use HasFactory;

    protected $fillable = [
        'version',
        'name',
        'slug',
        'rating',
        'city',
        'lat',
        'lon',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'lat' => 'decimal:6',
            'lon' => 'decimal:6',
            'version' => 'integer',
        ];
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }
}
