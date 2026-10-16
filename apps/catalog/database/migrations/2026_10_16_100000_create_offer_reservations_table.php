<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rezerwacje sztuk oferty. Wiersz zostaje po potwierdzeniu i zwolnieniu
        // (status `confirmed` / `released`): to pamięć idempotencji — ponowione
        // potwierdzenie musi odróżnić „już potwierdzona” od „nigdy nie istniała”.
        // Unikalność (offer_id, reservation_key) egzekwuje, że klucz Ordering
        // (`order-{id}`) rezerwuje daną ofertę najwyżej raz.
        // Precyzja 6, jak w `offer_price_changes`: czasy z domeny mają mikrosekundy.
        Schema::create('offer_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->string('reservation_key', 64);
            $table->unsignedInteger('quantity');
            $table->string('status', 16);
            $table->timestampTz('reserved_at', 6);
            $table->timestampTz('settled_at', 6)->nullable();

            $table->unique(['offer_id', 'reservation_key']);
            $table->index(['offer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_reservations');
    }
};
