<?php

namespace App\Models;

use App\Concerns\EmitsOutboxEvents;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

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
        'active',
    ];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'stock' => 'integer',
            'shipping_days' => 'integer',
            'active' => 'boolean',
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
     * Aktualizacja ceny i/lub stanu magazynowego jako osobne zdarzenia
     * domenowe. Zmiana obu naraz (np. promocja + dosprzedaż) generuje
     * DWA wpisy w outboksie, nie jeden ogólny "offer.updated" — dzięki temu
     * konsument (i przyszły dashboard analityczny) widzi, co się realnie
     * stało, a nie tylko że "coś się zmieniło" (docs/02-APLIKACJE.md,
     * katalog zdarzeń).
     */
    public function updateWithOutbox(array $attributes, string $defaultEventType = 'offer.updated', array $eventsByDirtyField = []): static
    {
        return $this->performUpdateWithOutbox($attributes, $defaultEventType, array_merge([
            'price_cents' => 'offer.price_changed',
            'stock' => 'offer.stock_changed',
        ], $eventsByDirtyField));
    }

    /**
     * Dezaktywacja jest kierunkowa (true -> false), więc nie pasuje do
     * generycznej mapy "zmienione pole -> event" z updateWithOutbox —
     * zmiana 'active' w drugą stronę (reaktywacja oferty) to inny fakt
     * biznesowy, nie ma dla niej osobnego zdarzenia w katalogu i zostaje
     * pod "offer.updated".
     */
    public function deactivateWithOutbox(): static
    {
        return DB::transaction(function () {
            $this->active = false;
            $this->version = $this->version + 1;
            $this->save();

            $this->recordOutboxEvent('offer.deactivated', $this->toOutboxPayload());

            return $this;
        });
    }
}
