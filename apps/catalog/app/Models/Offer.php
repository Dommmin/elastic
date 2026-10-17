<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Wiersz oferty dla odczytu, fabryk i seedera. Zapis z regułami idzie przez
 * agregat `SellerPortal\Domain\Offer\Offer` i `SqlOfferRepository`.
 *
 * DLACZEGO bez `EmitsOutboxEvents`: `updateWithOutbox()` na tym modelu
 * zapisywałby z pominięciem agregatu, ale wysyłał zdarzenie, więc obejście
 * wyglądałoby legalnie. `$fillable` zostaje (seeder, fabryki): zapis przez ten
 * model nadal jest MOŻLIWY — granicę trzyma przegląd kodu, nie kompilator.
 */
class Offer extends Model
{
    use HasFactory;

    private const string RESERVED_SQL = "COALESCE((SELECT SUM(quantity) FROM offer_reservations WHERE offer_reservations.offer_id = offers.id AND offer_reservations.status = 'active'), 0)";

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

    public function activeReservations(): HasMany
    {
        return $this->hasMany(OfferReservation::class)->where('status', 'active');
    }

    /**
     * Jedyna definicja „widoczna w wyszukiwarce” po stronie odczytu; zgodna
     * z `Offer::isListed()` w domenie. Zastępuje dawne `where('active', true)`
     * w projekcji, panelu produktów i śladzie zdarzenia.
     *
     * DLACZEGO podzapytanie, a nie kolumna: dostępność jest pochodną stanu
     * i aktywnych rezerwacji (jak `Offer::available()`), więc nie ma czego
     * synchronizować. Zwykły SQL działa tak samo na Postgresie i SQLite.
     */
    #[Scope]
    protected function listed(Builder $query): void
    {
        $query->where('status', 'published')
            ->whereRaw('stock > '.self::RESERVED_SQL);
    }

    /**
     * Dodaje kolumnę `available` (stan − aktywne rezerwacje), ta sama definicja co w `listed`.
     */
    #[Scope]
    protected function withAvailable(Builder $query): void
    {
        $query->select($query->getModel()->getTable().'.*')
            ->selectRaw('stock - '.self::RESERVED_SQL.' as available');
    }
}
