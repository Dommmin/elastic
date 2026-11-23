<?php

namespace App\Services\Search\Lab;

/**
 * Warianty wyszukiwania porównywane w laboratorium (ETAP 14, spec §6).
 * `knn-exact` to wariant diagnostyczny (brute force) — tylko do recall@10, nie do tabeli.
 */
enum LabVariant: string
{
    case Bm25 = 'bm25';
    case Knn = 'knn';
    case HybridBool = 'hybrid-bool';
    case RrfClient = 'rrf-client';
    case RrfNative = 'rrf-native';
    case Elser = 'elser';
    case KnnExact = 'knn-exact';

    /**
     * Czy wariant da się zapisać jako jedno `query` — tylko takie przyjmuje `_rank_eval`
     * (retriever `rrf` i fuzja w PHP nie są zapytaniem).
     */
    public function isQueryExpressible(): bool
    {
        return match ($this) {
            self::Bm25, self::Knn, self::HybridBool, self::Elser => true,
            default => false,
        };
    }

    /**
     * Czy wariant wysyła zapytanie `knn` (stałe k = `LabQueryBuilder::K`).
     */
    public function usesKnn(): bool
    {
        return match ($this) {
            self::Knn, self::HybridBool, self::RrfClient, self::RrfNative, self::KnnExact => true,
            default => false,
        };
    }

    /**
     * Czy wariant potrzebuje licencji trial. Samo zapytanie `knn` działa na basic, ale wektor
     * zapytania liczy inference ES (endpoint `lab-e5`), a to funkcja ML — więc wszystko poza
     * `bm25` wymaga trial; `rrf-native` dodatkowo z powodu retrievera `rrf`.
     */
    public function requiresTrial(): bool
    {
        return $this !== self::Bm25;
    }

    /**
     * Warianty głównej tabeli ETAPU 14a (bez `elser` z 14b i diagnostycznego `knn-exact`).
     *
     * @return list<self>
     */
    public static function table(): array
    {
        return [self::Bm25, self::Knn, self::HybridBool, self::RrfClient, self::RrfNative];
    }
}
