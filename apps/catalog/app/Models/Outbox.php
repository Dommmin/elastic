<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Outbox extends Model
{
    public $timestamps = false;

    protected $table = 'outbox';

    protected $fillable = [
        'event_id',
        'aggregate_type',
        'aggregate_id',
        'event_type',
        'payload',
        'sequence',
        'occurred_at',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'sequence' => 'integer',
            'occurred_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
