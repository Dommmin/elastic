<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Wiersz rezerwacji do odczytu (relacja `Offer::activeReservations`). Zapis idzie
 * wyłącznie przez agregat i `SqlOfferRepository`.
 */
class OfferReservation extends Model
{
    public $timestamps = false;
}
