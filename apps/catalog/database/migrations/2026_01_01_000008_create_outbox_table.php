<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Transactional Outbox (docs/05-SPOJNOSC-DANYCH.md, sekcja 3).
     *
     * Wiersz tu wstawiony ZAWSZE w tej samej transakcji SQL co zmiana
     * encji domenowej (patrz App\Concerns\EmitsOutboxEvents). Dzięki temu
     * albo obie zmiany się zapiszą, albo żadna — nie da się zgubić eventu
     * przez awarię między UPDATE a publikacją do RabbitMQ.
     */
    public function up(): void
    {
        Schema::create('outbox', function (Blueprint $table) {
            $table->id();
            // ULID jako klucz idempotencji po stronie konsumenta
            // (docs/05, sekcja 4 — deduplikacja po event_id).
            $table->ulid('event_id')->unique();
            $table->string('aggregate_type');
            $table->string('aggregate_id');
            $table->string('event_type');
            $table->jsonb('payload');
            // Kopia wersji agregatu w momencie zdarzenia — to samo pole co
            // `version` w tabeli źródłowej, przenoszone do ES jako
            // `version_type=external` (docs/05, sekcja 3.5).
            $table->unsignedBigInteger('sequence');
            $table->timestampTz('occurred_at');
            $table->timestampTz('published_at')->nullable();

            // Zapytanie publikatora: WHERE published_at IS NULL ORDER BY id
            // LIMIT n FOR UPDATE SKIP LOCKED — ten indeks je obsługuje.
            $table->index(['published_at', 'id']);
            $table->index(['aggregate_type', 'aggregate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox');
    }
};
