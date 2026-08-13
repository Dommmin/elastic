<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            // UPROSZCZENIE ŚWIADOME (docs/02-APLIKACJE.md wspomina typ Postgresa
            // `ltree` dla hierarchii). Realny `ltree` wymaga rozszerzenia bazy
            // i własnego castu Eloquenta — dodajemy to jako ulepszenie w module
            // o modelowaniu (moduł 4), nie teraz. Materialized path jako zwykły
            // string ("1.4.17") daje 90% korzyści przy 10% kosztu i jest
            // czytelny od razu w `_source` dokumentu ES (pole category.path).
            $table->string('path')->nullable()->index();
            // "category.renamed" jest w katalogu zdarzeń (docs/02-APLIKACJE.md)
            // i wywołuje fan-out (update_by_query na produktach tej kategorii)
            // — potrzebuje tej samej wersji zewnętrznej co pozostałe agregaty.
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
