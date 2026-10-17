<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Symulowany zewnętrzny dostawca płatności: celowo bez kluczy obcych do
        // tabel marketplace'u. `reference` to tylko napis, który bramka odsyła
        // w webhooku; o jego znaczeniu (`order-{id}`) wie wyłącznie Ordering.
        Schema::create('fake_gateway_payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('reference', 64);
            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 3);
            $table->string('status', 16);
            $table->timestampTz('expires_at', 6);
            $table->text('return_url');
            $table->timestampTz('decided_at', 6)->nullable();
            $table->timestampsTz();

            $table->index('reference');
        });

        // Unikalny `idempotency_key` to cała idempotencja zwrotu: ten sam klucz
        // trafia w ten sam wiersz, więc drugi zwrot nie powstaje.
        Schema::create('fake_gateway_refunds', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 64)->unique();
            $table->ulid('payment_id');
            $table->unsignedBigInteger('amount_cents');
            $table->string('status', 16);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampsTz();

            $table->foreign('payment_id')->references('id')->on('fake_gateway_payments')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fake_gateway_refunds');
        Schema::dropIfExists('fake_gateway_payments');
    }
};
