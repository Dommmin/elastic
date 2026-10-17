<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Koszyk kupującego. `added_price_cents` to cena, którą kupujący widział
        // i zaakceptował: dzięki niej koszyk pokaże „cena zmieniona z X na Y”,
        // a formularz złożenia zamówień wyśle właśnie ją (H5). Unikalność
        // (user_id, offer_id) sprawia, że ta sama oferta to jedna pozycja z sumą ilości.
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->unsignedSmallInteger('quantity');
            $table->unsignedBigInteger('added_price_cents');
            $table->timestamps();

            $table->unique(['user_id', 'offer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
