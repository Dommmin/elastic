<?php

namespace SellerPortal\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;

/**
 * Tożsamość agregatu powstaje PRZED zapisem (zdarzenia niosą ID od początku),
 * więc repozytorium musi ją pobrać z bazy bez wstawiania wiersza.
 *
 * Postgres: kolejna wartość sekwencji `{tabela}_id_seq`. INSERT z jawnie
 * podanym ID z tej sekwencji nie rozjeżdża jej z tabelą.
 *
 * Inne sterowniki (SQLite): `MAX(id)+1`. To skrót WYŁĄCZNIE dla testów
 * jednowątkowych: przy współbieżnym zapisie dwa procesy dostałyby to samo ID.
 */
final class NextId
{
    public static function for(string $table): int
    {
        if (DB::getDriverName() === 'pgsql') {
            return (int) DB::scalar("SELECT nextval('{$table}_id_seq')");
        }

        return (int) DB::table($table)->max('id') + 1;
    }
}
