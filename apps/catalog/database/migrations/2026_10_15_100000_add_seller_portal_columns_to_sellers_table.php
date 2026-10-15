<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sellers', function (Blueprint $table) {
            // Sprzedawca bez konta (user_id null) to dane z seedera; konto
            // może zniknąć bez kasowania sprzedawcy i jego ofert.
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            // Istniejące wiersze dostają `active`: byli sprzedawcami, zanim powstał portal.
            $table->string('status', 16)->default('active');
            $table->text('status_reason')->nullable();
            $table->timestampTz('status_changed_at')->nullable();
        });

        // CHECK tylko w Postgresie: SQLite nie dodaje ograniczeń przez ALTER
        // TABLE. W testach (SQLite) wartości pilnuje enum domeny.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE sellers ADD CONSTRAINT sellers_status_check CHECK (status IN ('pending', 'active', 'rejected', 'blocked'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sellers DROP CONSTRAINT sellers_status_check');
        }

        // SQLite nie skasuje kolumny, która nadal siedzi w indeksie.
        Schema::table('sellers', function (Blueprint $table) {
            $table->dropUnique(['user_id']);
        });
        Schema::table('sellers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['status', 'status_reason', 'status_changed_at']);
        });
    }
};
