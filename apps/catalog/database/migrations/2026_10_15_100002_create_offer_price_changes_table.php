<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Historia pod dobowy limit zmian ceny: repozytorium ładuje tylko
        // wiersze z ostatnich 24 h, więc indeks po (offer_id, changed_at).
        // Precyzja 6 (mikrosekundy): Postgres zaokrągla timestamptz do sekund, a
        // repozytorium porównuje wpisy z czasem z domeny, więc zaokrąglenie
        // gubiłoby zmiany ceny z tej samej sekundy.
        Schema::create('offer_price_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->timestampTz('changed_at', 6);

            $table->index(['offer_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_price_changes');
    }
};
