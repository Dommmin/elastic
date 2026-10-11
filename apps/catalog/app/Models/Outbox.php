<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Outbox extends Model
{
    public $timestamps = false;

    protected $table = 'outbox';

    /**
     * Mikrosekundy zamiast domyślnych pełnych sekund: krok „w ES” w drodze
     * zdarzenia porównuje `occurred_at` w µs z `_version` dokumentu
     * (`projected_at_us`). Ucięte do sekundy zapalałoby się nawet do sekundy
     * za wcześnie, dla projekcji sprzed zdarzenia.
     */
    protected $dateFormat = 'Y-m-d H:i:s.u';

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
