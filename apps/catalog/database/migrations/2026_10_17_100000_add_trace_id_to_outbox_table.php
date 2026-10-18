<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // trace_id żądania, które zapisało zdarzenie (32 znaki hex). Publisher
        // wysyła go jako nagłówek AMQP, żeby konsument kontynuował ten sam ślad.
        // Nullable: wiersze sprzed tej migracji nie mają śladu.
        Schema::table('outbox', function (Blueprint $table) {
            $table->char('trace_id', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('outbox', function (Blueprint $table) {
            $table->dropColumn('trace_id');
        });
    }
};
