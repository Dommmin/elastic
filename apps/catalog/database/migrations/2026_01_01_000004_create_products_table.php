<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            // Dowolne cechy produktu (kolor, materiał, pojemność...) bez
            // eksplozji kolumn. Po stronie ES odpowiednikiem jest typ
            // `flattened` (docs/03-SCIEZKA-NAUKI.md, moduł 4) — świadomie
            // ten sam kształt danych po obu stronach.
            $table->jsonb('attributes')->nullable();
            $table->string('ean', 13)->nullable()->unique();
            // Wersja monotoniczna — patrz komentarz w migracji sellers.
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
