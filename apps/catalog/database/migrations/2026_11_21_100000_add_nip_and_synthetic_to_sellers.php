<?php

use App\Support\TaxIdGenerator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ETAP 13: NIP sprzedawcy (klucz do rejestru) i flaga `synthetic` (dane z generatora).
 *
 * DLACZEGO trzy kroki dla `nip`: kolumna not null z unikalnym indeksem nie może
 * powstać na tabeli z istniejącymi wierszami. Najpierw nullable, potem wypełnienie
 * deterministycznym NIP-em z ID sprzedawcy, na końcu not null i unikalność.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sellers', function (Blueprint $table) {
            $table->string('nip', 10)->nullable();
            $table->boolean('synthetic')->default(false);
        });

        DB::table('sellers')->orderBy('id')->each(function (object $seller): void {
            DB::table('sellers')->where('id', $seller->id)->update(['nip' => TaxIdGenerator::forSeed((int) $seller->id)]);
        });

        Schema::table('sellers', function (Blueprint $table) {
            $table->string('nip', 10)->nullable(false)->change();
            $table->unique('nip', 'sellers_nip_unique');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('synthetic')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('synthetic');
        });

        // SQLite nie skasuje kolumny, która nadal siedzi w indeksie.
        Schema::table('sellers', function (Blueprint $table) {
            $table->dropUnique('sellers_nip_unique');
        });
        Schema::table('sellers', function (Blueprint $table) {
            $table->dropColumn(['nip', 'synthetic']);
        });
    }
};
