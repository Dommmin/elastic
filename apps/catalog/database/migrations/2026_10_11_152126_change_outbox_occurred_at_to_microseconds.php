<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Krok „w ES” w drodze zdarzenia porównuje `occurred_at` w µs z `_version`
     * dokumentu (`projected_at_us`). `timestamptz(0)` zaokrągla do sekundy:
     * zdarzenie z 12:00:00.7 zapisane jako 12:00:01 nie „dotarłoby” do ES
     * po projekcji z 12:00:00.9, a polling czekałby na następne zdarzenie.
     */
    public function up(): void
    {
        Schema::table('outbox', function (Blueprint $table) {
            $table->timestampTz('occurred_at', 6)->change();
        });
    }

    public function down(): void
    {
        Schema::table('outbox', function (Blueprint $table) {
            $table->timestampTz('occurred_at', 0)->change();
        });
    }
};
