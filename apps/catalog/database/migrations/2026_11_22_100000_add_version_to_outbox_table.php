<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Wersja schematu ładunku koperty (nie agregatu — to robi `sequence`).
        // Domyślnie 1; `user.*` z atrybucją do sprzedawcy (ETAP 13) idą jako 2.
        Schema::table('outbox', function (Blueprint $table) {
            $table->unsignedSmallInteger('version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('outbox', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};
