<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ETAP 13: kontekst Billing. Konto rozliczeniowe sprzedawcy (wersja do
 * optymistycznej blokady) i jego faktury. Bez klucza obcego do `sellers`:
 * konteksty nie wiążą się schematem, a `seller_id` to tylko liczba.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('seller_id')->primary();
            $table->unsignedBigInteger('version')->default(0);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->string('id', 26)->primary();
            $table->unsignedBigInteger('seller_id');
            $table->unsignedBigInteger('amount_cents');
            $table->timestampTz('issued_at', 6);
            $table->timestampTz('due_at', 6);
            $table->timestampTz('paid_at', 6)->nullable();
            $table->string('status', 16);

            $table->index(['seller_id', 'status']);
            // Sweep zaległości szuka faktur otwartych po terminie.
            $table->index(['status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('billing_accounts');
    }
};
