<?php

namespace App\Console\Commands;

use App\Models\Seller;
use Illuminate\Console\Command;
use SellerPortal\Application\Seller\RenameSeller;
use SellerPortal\Application\Seller\RenameSellerHandler;
use SharedKernel\InvalidValue;

/**
 * Zmiana nazwy sprzedawcy ścieżką domenową (ETAP 13): `RenameSellerHandler` wczytuje agregat,
 * woła `Seller::rename`, zapisuje (wersja rośnie o 1) i rozsyła `SellerRenamed`.
 *
 * Projekcja czyta nazwę sprzedawcy na żywo (`offers[].seller`), więc każda przyszła
 * projekcja już ją ma. Dokumenty, które w ES są teraz, poprawia osobno
 * `search:seller:rename` (`_update_by_query`) — zamiast 100 tys. zdarzeń product.updated.
 * Ta sama nazwa co dotychczasowa to brak zmiany: bez zdarzenia i bez podniesienia wersji.
 */
class SellerRenameCommand extends Command
{
    protected $signature = 'marketplace:seller-rename
        {seller_id : ID sprzedawcy}
        {name : Nowa nazwa}';

    protected $description = 'Zmienia nazwę sprzedawcy przez agregat (SellerRenamed); ES poprawia search:seller:rename';

    public function handle(RenameSellerHandler $handler): int
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

        try {
            $handler->handle(new RenameSeller($seller->id, $name));
        } catch (InvalidValue $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Sprzedawca {$seller->id}: „{$old}” → „{$name}” (przez agregat; ES poprawia search:seller:rename).");

        return self::SUCCESS;
    }
}
