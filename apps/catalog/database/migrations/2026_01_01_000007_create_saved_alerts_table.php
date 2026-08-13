<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Zapytanie zapisane jako JSON — to samo ciało trafi jako
            // percolator query do indeksu `alerts-percolator` (moduł 13,
            // docs/03-SCIEZKA-NAUKI.md). Np. {"query":"iphone 15","price_max":300000}.
            $table->jsonb('query');
            $table->string('channel')->default('email');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_alerts');
    }
};
