<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use SellerPortal\Domain\Offer\OfferId;
use SellerPortal\Domain\Offer\OfferRepository;
use SellerPortal\Domain\Offer\Stock;
use SharedKernel\Money;
use Throwable;

/**
 * `php artisan marketplace:churn` — generator ruchu pod ETAP 10: zmienia cenę
 * i stan losowych ofert oraz usuwa część produktów, gdy równolegle trwa
 * reindeks, żeby było widać, że zmiany nie giną.
 *
 * DLACZEGO przez agregat i repozytorium, a nie `UPDATE`: `save()` zapisuje
 * zdarzenia `offer.*` do outboxu w tej samej transakcji (jak portal i panel),
 * a usunięcie idzie przez `deleteWithOutbox('product.deleted')`. Zdarzeń nie
 * wstawiamy ręcznie. Zmiana ceny podlega strażnikowi (limit 5/dobę), więc gdy
 * agregat ją odrzuci, zmieniamy stan magazynowy.
 *
 * Ostatnia linia: `updated=… deleted=… since=<ISO 8601>`; `since` to moment
 * startu — od niego wolno szukać zdarzeń tego przebiegu.
 */
class ChurnMarketplaceCommand extends Command
{
    protected $signature = 'marketplace:churn
        {--rate=20 : Zmian na sekundę}
        {--duration=60 : Czas trwania w sekundach}
        {--delete-ratio=0.05 : Jaka część zmian to usunięcie produktu (0..1)}';

    protected $description = 'Zmienia ceny/stany ofert i usuwa produkty przez ścieżki domenowe z outboxem (ruch pod reindeks)';

    public function handle(OfferRepository $offers): int
    {
        $rate = (int) $this->option('rate');
        $duration = (int) $this->option('duration');
        $deleteRatio = (float) $this->option('delete-ratio');

        if ($rate < 1 || $duration < 1 || $deleteRatio < 0 || $deleteRatio > 1) {
            $this->error('--rate i --duration muszą być ≥ 1, a --delete-ratio w przedziale 0..1.');

            return self::FAILURE;
        }

        $since = now()->toIso8601String();
        $interval = 1.0 / $rate;
        $deadline = microtime(true) + $duration;
        $next = microtime(true);
        $updated = 0;
        $deleted = 0;

        while (microtime(true) < $deadline) {
            if (mt_rand() / mt_getrandmax() < $deleteRatio) {
                $deleted += $this->deleteRandomProduct() ? 1 : 0;
            } else {
                $updated += $this->changeRandomOffer($offers) ? 1 : 0;
            }

            // Równe tempo bez dryfu: czekamy do zaplanowanej chwili, a gdy
            // zmiana trwała dłużej niż okres, nadrabiamy bez spania.
            $next += $interval;
            $sleep = $next - microtime(true);

            if ($sleep > 0) {
                usleep((int) ($sleep * 1_000_000));
            }
        }

        $this->info("updated={$updated} deleted={$deleted} since={$since}");

        return self::SUCCESS;
    }

    private function changeRandomOffer(OfferRepository $offers): bool
    {
        $id = $this->randomId('offers');

        if ($id === null) {
            return false;
        }

        try {
            $offer = $offers->get(OfferId::fromInt($id));
            $now = now()->toDateTimeImmutable();

            try {
                // Zmiana ≤ 10% — poniżej progu moderacji, więc stosuje się od razu.
                $factor = mt_rand(90, 110) / 100;
                $offer->changePrice(Money::fromCents(max(100, (int) round($offer->price()->cents() * $factor)), $offer->price()->currency()), $now);
            } catch (Throwable) {
                // Limit dobowy/status oferty: zamiast ceny ruszamy stan magazynowy.
                $offer = $offers->get(OfferId::fromInt($id));
                $offer->changeStock(Stock::fromInt(mt_rand(1, 60)), $now);
            }

            $offers->save($offer);

            return true;
        } catch (Throwable) {
            // Oferta zniknęła razem z produktem albo zapis zderzył się z innym: pomijamy.
            return false;
        }
    }

    private function deleteRandomProduct(): bool
    {
        $id = $this->randomId('products');
        $product = $id === null ? null : Product::query()->find($id);

        if ($product === null) {
            return false;
        }

        try {
            $product->deleteWithOutbox('product.deleted');

            return true;
        } catch (Throwable) {
            // Np. produkt z zamówieniami (FK bez kaskady): zostaje na miejscu.
            return false;
        }
    }

    /**
     * Losowy istniejący identyfikator bez `ORDER BY random()` (na 300 tys. wierszy
     * to pełny skan): losujemy punkt z przedziału i bierzemy najbliższy wyższy.
     */
    private function randomId(string $table): ?int
    {
        $bounds = DB::table($table)->selectRaw('MIN(id) as lo, MAX(id) as hi')->first();

        if ($bounds === null || $bounds->lo === null) {
            return null;
        }

        $id = DB::table($table)->where('id', '>=', mt_rand((int) $bounds->lo, (int) $bounds->hi))->min('id');

        return $id === null ? null : (int) $id;
    }
}
