<?php

namespace App\Models;

use App\Concerns\EmitsOutboxEvents;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedAlert extends Model
{
    use EmitsOutboxEvents;
    use HasFactory;

    protected $fillable = ['user_id', 'query', 'channel', 'active'];

    protected function casts(): array
    {
        return [
            'query' => 'array',
            'active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function usesOutboxVersioning(): bool
    {
        return false;
    }
}
