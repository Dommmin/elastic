<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Precyzja 6 przy czasach, jak w `offer_reservations`: czasy z domeny mają mikrosekundy,
        // a sweep porównuje `payment_deadline` w SQL.
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_user_id')->constrained('users');
            $table->foreignId('seller_id')->constrained('sellers');
            $table->string('status', 24);
            $table->char('currency', 3);
            $table->unsignedBigInteger('total_cents');
            $table->timestampTz('placed_at', 6);
            $table->timestampTz('payment_deadline', 6);
            $table->string('payment_id', 64)->nullable();
            $table->unsignedBigInteger('paid_amount_cents')->nullable();
            $table->timestampTz('paid_at', 6)->nullable();
            $table->string('rejection_reason', 32)->nullable();
            $table->unsignedBigInteger('rejection_offer_id')->nullable();
            $table->string('cancelled_by', 16)->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->string('tracking_number', 64)->nullable();
            $table->timestampTz('shipped_at', 6)->nullable();
            // Wersja do optymistycznej blokady (jak w `offers`).
            $table->unsignedInteger('version');
            $table->timestamps();

            // Sweep wygaśnięć.
            $table->index(['status', 'payment_deadline']);
            // „Moje zamówienia” kupującego.
            $table->index(['buyer_user_id', 'id']);
            // Lista zamówień w portalu sprzedawcy.
            $table->index(['seller_id', 'status']);
        });

        // `offer_id` z FK: oferty nie są kasowane (wycofanie to status), a pozycja bez oferty
        // nie miałaby czego zwolnić w sadze.
        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->foreignId('offer_id')->constrained('offers');
            $table->string('product_name');
            $table->unsignedBigInteger('unit_price_cents');
            $table->unsignedSmallInteger('quantity');
            $table->string('status', 16);

            $table->unique(['order_id', 'offer_id']);
        });

        Schema::create('order_refunds', function (Blueprint $table) {
            $table->id();
            $table->string('refund_id', 64)->unique();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('payment_id', 64);
            $table->unsignedBigInteger('amount_cents');
            $table->string('reason', 32);
            $table->string('status', 16);
            $table->timestampTz('requested_at', 6);
            $table->timestampTz('completed_at', 6)->nullable();

            $table->index('order_id');
        });

        // Dziennik zdarzeń zamówienia pod oś czasu kupującego. To NIE jest outbox:
        // zdarzenia Order nie opuszczają kontekstu.
        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('event_type', 64);
            $table->json('payload');
            $table->timestampTz('occurred_at', 6);

            $table->index(['order_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('order_refunds');
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('orders');
    }
};
