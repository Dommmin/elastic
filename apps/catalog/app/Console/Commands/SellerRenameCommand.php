<?php

namespace App\Console\Commands;

use App\Models\Seller;
use Illuminate\Console\Command;

/**
 * Zmiana nazwy sprzedawcy — TYLKO w Postgresie, bez zdarzeń w outboxie (ETAP 10, spec 5.7).
 *
 * Projekcja czyta nazwę sprzedawcy na żywo (`offers[].seller`), więc każda przyszła
 * projekcja już ją ma. Dokumenty, które w ES są teraz, poprawia osobno
 * `search:seller:rename` (`_update_by_query`) — zamiast 100 tys. zdarzeń product.updated.
 * Dlatego zapis jest zwykłym UPDATE-em, nie `updateWithOutbox`; `version` agregatu
 * zostaje nietknięta (nie ma zdarzenia, któremu miałaby nadać numer).
 */
class SellerRenameCommand extends Command
{
    protected $signature = 'marketplace:seller-rename
        {seller_id : ID sprzedawcy}
        {name : Nowa nazwa}';

    protected $description = 'Zmienia nazwę sprzedawcy w bazie (bez outboxu); ES poprawia search:seller:rename';

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));
        if ($name === '') {
            $this->error('Nazwa nie może być pusta.');

            return self::FAILURE;
        }

        $seller = Seller::query()->find((int) $this->argument('seller_id'));
        if ($seller === null) {
            $this->error("Nie ma sprzedawcy o ID {$this->argument('seller_id')}.");

            return self::FAILURE;
        }

        $old = $seller->name;
        Seller::query()->whereKey($seller->id)->update(['name' => $name, 'updated_at' => now()]);

        $this->info("Sprzedawca {$seller->id}: „{$old}” → „{$name}” (tylko baza; outbox bez zmian).");

        return self::SUCCESS;
    }
}
