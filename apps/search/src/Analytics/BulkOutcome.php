<?php

namespace App\Analytics;

/**
 * Wynik jednego `_bulk` do data streamów. `rejected` i `retryable` to referencje
 * `<indeks>/<_id>` (patrz ref()), po których handler odnajduje wiadomość.
 */
final readonly class BulkOutcome
{
    /**
     * @param  list<string>  $rejected   błąd trwały (np. mapper_parsing_exception) — ponawianie nic nie da
     * @param  list<string>  $retryable  429/5xx — przejściowe, ma sens ponowić
     * @param  array<string, string>  $rejectedErrors  ref → `error.type: error.reason` z ES (do komunikatu w `failed`)
     */
    public function __construct(
        public int $created = 0,
        public int $duplicates = 0,
        public array $rejected = [],
        public array $retryable = [],
        public array $rejectedErrors = [],
    ) {
    }

    public static function ref(string $index, string $id): string
    {
        return $index.'/'.$id;
    }
}
