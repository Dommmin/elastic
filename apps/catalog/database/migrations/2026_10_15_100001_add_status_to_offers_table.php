<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->string('status', 16)->default('draft');
            $table->string('suspension_kind', 16)->nullable();
            $table->text('suspension_note')->nullable();
            $table->unsignedBigInteger('suspended_by')->nullable();
            $table->unsignedBigInteger('pending_price_cents')->nullable();
            $table->timestampTz('pending_requested_at', 6)->nullable();
        });

        // Dane przed usunięciem `active`: aktywna oferta jest opublikowana,
        // nieaktywna to wycofana (admin mógł ją tylko dezaktywować).
        DB::statement("UPDATE offers SET status = CASE WHEN active THEN 'published' ELSE 'withdrawn' END");

        // Stary indeks zawiera `active`; SQLite nie pozwala skasować kolumny
        // używanej w indeksie.
        Schema::table('offers', function (Blueprint $table) {
            $table->dropIndex(['product_id', 'active']);
        });
        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn('active');
            $table->index(['product_id', 'status', 'stock']);
        });

        // CHECK tylko w Postgresie (SQLite nie dodaje ograniczeń przez ALTER
        // TABLE) — w testach wartości pilnuje enum domeny.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE offers ADD CONSTRAINT offers_status_check CHECK (status IN ('draft', 'published', 'suspended', 'withdrawn'))");
        }

        // Jedna żywa oferta sprzedawcy na produkt, wycofane nie blokują.
        // Indeks częściowy działa w Postgresie i SQLite; to gwarancja na
        // wypadek wyścigu, który przepuści sprawdzenie w aplikacji.
        DB::statement("CREATE UNIQUE INDEX offers_one_live_per_seller_product ON offers (seller_id, product_id) WHERE status <> 'withdrawn'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX offers_one_live_per_seller_product');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE offers DROP CONSTRAINT offers_status_check');
        }

        Schema::table('offers', function (Blueprint $table) {
            $table->boolean('active')->default(true);
        });

        DB::statement("UPDATE offers SET active = (status = 'published')");

        Schema::table('offers', function (Blueprint $table) {
            $table->dropIndex(['product_id', 'status', 'stock']);
        });
        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn([
                'status',
                'suspension_kind',
                'suspension_note',
                'suspended_by',
                'pending_price_cents',
                'pending_requested_at',
            ]);
            $table->index(['product_id', 'active']);
        });
    }
};
