<?php

namespace App\Seller360\Rebuild;

/**
 * Wynik przebudowy: `switched` (aliasy przepięte) albo `aborted` (rozjazd ponad tolerancję: aliasy bez zmian,
 * podwójny zapis zatrzymany, nowe indeksy zostają do oględzin).
 */
final class RebuildReport
{
    /** @var array<string, int> czas faz w ms (create, dual, backfill_*, verify, switch, enrich, delete_old) */
    public array $phasesMs = [];

    /** @var array<string, int> zapisane sekcje backfillu (created|updated) per sekcja; noop się nie liczy */
    public array $backfill = [];

    /** @var array{main?: int, public?: int} */
    public array $countsOld = [];

    /** @var array{main?: int, public?: int} */
    public array $countsNew = [];

    public int $sampled = 0;

    /** @var list<string> opisy rozjazdów (po ostatniej próbie porównania) */
    public array $mismatches = [];

    public string $status = 'running';

    public bool $deletedOld = false;

    public ?string $enrichError = null;

    public function __construct(
        public readonly string $oldMain,
        public readonly string $oldPublic,
        public readonly string $newMain,
        public readonly string $newPublic,
    ) {
    }
}
