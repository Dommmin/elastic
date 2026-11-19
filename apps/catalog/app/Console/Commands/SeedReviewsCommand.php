<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * `php artisan marketplace:seed-reviews {--products=50000} {--seed=10}` — opinie
 * dla części produktów, żeby `rating_avg` w ES miał prawdziwe wartości (seed
 * katalogu opinii nie tworzy; bez nich zmiana typu pola w ETAPIE 10, half_float →
 * scaled_float, byłaby niewidoczna na danych).
 *
 * Co piąty wybrany produkt dostaje 10 opinii o sumie 47 (średnia dokładnie
 * 4,70), co piąty 10 opinii o sumie 33 (3,30): to wartości, których `half_float`
 * nie reprezentuje (4,70 → ≈ 4,6992, więc `rating_avg >= 4.7` je gubi).
 * Pozostałe produkty mają 1–10 opinii o losowych ocenach.
 *
 * DLACZEGO jedno zdarzenie na produkt, a nie na opinię: tak samo jak w
 * `marketplace:seed` — `search-consumer` i tak robi pełny odczyt projekcji
 * (średnia liczona z całej tabeli `reviews`), więc jedno `review.created`
 * po dodaniu wszystkich opinii produktu wystarcza i nie mnoży ruchu w kolejce
 * (50 tys. produktów × ~7 opinii = 350 tys. zdarzeń zamiast 50 tys.). Zdarzenie
 * idzie ścieżką domenową `Review::createWithOutbox()` (ostatnia opinia produktu);
 * jego `aggregate_id` to id opinii, a id produktu niesie payload (`product_id`) —
 * po nim `ProductSyncHandler` rozpoznaje, który dokument odświeżyć.
 *
 * Idempotencja: wybieramy tylko produkty BEZ opinii, więc ponowny przebieg
 * niczego nie dubluje. Wybór jest deterministyczny dla danego `--seed`.
 *
 * Ostatnia linia: `products=… reviews=… avg_4_70=… avg_3_30=…`.
 */
class SeedReviewsCommand extends Command
{
    private const REVIEWERS = 10;

    /** @var array<int, int> Zestawy ocen: 10 opinii, suma 47 i 33. */
    private const RATINGS_4_70 = [5, 5, 5, 5, 5, 5, 5, 4, 4, 4];

    private const RATINGS_3_30 = [4, 4, 4, 3, 3, 3, 3, 3, 3, 3];

    protected $signature = 'marketplace:seed-reviews
        {--products=50000 : Ilu produktom bez opinii dodać opinie}
        {--seed=10 : Ziarno wyboru produktów i ocen (ten sam seed = ten sam wybór)}';

    protected $description = 'Dodaje opinie części produktów (średnie m.in. 4,70 i 3,30) i emituje review.created do outboxu';

    public function handle(): int
    {
        $count = (int) $this->option('products');

        if ($count < 0) {
            $this->error('--products musi być ≥ 0.');

            return self::FAILURE;
        }

        $random = new Randomizer(new Xoshiro256StarStar(hash('sha256', 'seed-reviews:'.$this->option('seed'), true)));
        $reviewerIds = $this->reviewerIds();

        $candidates = Product::query()->whereDoesntHave('reviews')->orderBy('id')->pluck('id')->all();
        $chosen = array_slice($random->shuffleArray($candidates), 0, $count);
        sort($chosen);

        $reviews = 0;
        $avg470 = 0;
        $avg330 = 0;

        foreach ($chosen as $index => $productId) {
            $ratings = match ($index % 5) {
                0 => self::RATINGS_4_70,
                1 => self::RATINGS_3_30,
                default => $this->randomRatings($random),
            };

            $avg470 += $index % 5 === 0 ? 1 : 0;
            $avg330 += $index % 5 === 1 ? 1 : 0;
            $reviews += count($ratings);

            $this->addReviews($productId, $random->shuffleArray($ratings), $reviewerIds);
        }

        $this->info(sprintf('products=%d reviews=%d avg_4_70=%d avg_3_30=%d', count($chosen), $reviews, $avg470, $avg330));

        return self::SUCCESS;
    }

    /**
     * Wszystkie opinie produktu w jednej transakcji: pierwsze hurtowo, ostatnia
     * przez `createWithOutbox()`, żeby zdarzenie wyszło dopiero, gdy stan jest pełny.
     *
     * @param  array<int, int>  $ratings
     * @param  array<int, int>  $reviewerIds
     */
    private function addReviews(int $productId, array $ratings, array $reviewerIds): void
    {
        DB::transaction(function () use ($productId, $ratings, $reviewerIds) {
            $last = array_pop($ratings);
            $now = now();

            $rows = [];
            foreach ($ratings as $i => $rating) {
                $rows[] = [
                    'product_id' => $productId,
                    'user_id' => $reviewerIds[$i % count($reviewerIds)],
                    'rating' => $rating,
                    'body' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows !== []) {
                Review::query()->insert($rows);
            }

            Review::createWithOutbox([
                'product_id' => $productId,
                'user_id' => $reviewerIds[count($ratings) % count($reviewerIds)],
                'rating' => $last,
                'body' => null,
            ], 'review.created');
        });
    }

    /**
     * 1–10 ocen 1–5, z przechyleniem ku wysokim (jak w prawdziwych sklepach).
     *
     * @return array<int, int>
     */
    private function randomRatings(Randomizer $random): array
    {
        $weighted = [1, 2, 2, 3, 3, 3, 4, 4, 4, 4, 5, 5, 5, 5, 5];
        $ratings = [];

        for ($i = $random->getInt(1, 10); $i > 0; $i--) {
            $ratings[] = $weighted[$random->getInt(0, count($weighted) - 1)];
        }

        return $ratings;
    }

    /**
     * Stała pula autorów opinii (tworzona raz, ponowne przebiegi ją reużywają).
     *
     * @return array<int, int>
     */
    private function reviewerIds(): array
    {
        $ids = [];

        for ($i = 1; $i <= self::REVIEWERS; $i++) {
            $ids[] = User::query()->firstOrCreate(
                ['email' => "recenzent-{$i}@seed.test"],
                ['name' => "Recenzent {$i}", 'password' => Str::random(32), 'email_verified_at' => now()],
            )->id;
        }

        return $ids;
    }
}
