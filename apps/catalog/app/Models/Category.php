<?php

namespace App\Models;

use App\Concerns\EmitsOutboxEvents;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use EmitsOutboxEvents;
    use HasFactory;

    protected $fillable = ['version', 'parent_id', 'name', 'slug', 'path'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
