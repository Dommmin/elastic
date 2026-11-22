<?php

namespace App\Seller360\Complaints;

/**
 * Wynik importu reklamacji: `indexed` trafiły do `complaints` (sprzedawca rozpoznany po NIP),
 * `unmatched` do `complaints-unmatched` (reroute), `failed` to wiersze odrzucone przez parser lub ES.
 * `sections` to liczba zapisanych sekcji `complaints` w seller-360.
 */
final readonly class ImportResult
{
    public function __construct(
        public int $indexed = 0,
        public int $unmatched = 0,
        public int $failed = 0,
        public int $sections = 0,
    ) {
    }
}
