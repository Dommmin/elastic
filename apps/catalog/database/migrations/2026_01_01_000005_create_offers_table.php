<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('sellers')->cascadeOnDelete();
            $table->unsignedBigInteger('price_cents');
            $table->char('currency', 3)->default('PLN');
            $table->unsignedInteger('stock')->default(0);
            $table->enum('condition', ['new', 'used'])->default('new');
            $table->unsignedTinyInteger('shipping_days')->default(3);
            $table->boolean('active')->default(true);
            // Wersja monotoniczna — KLUCZOWA tutaj. To pole staje się
            // "sequence" w evencie offer.price_changed/.stock_changed i chroni
            // przed cofnięciem ceny przy nieuporządkowanym dostarczeniu
            // wiadomości z RabbitMQ (docs/05-SPOJNOSC-DANYCH.md, sekcja 3.5).
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();

            $table->index(['product_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};
