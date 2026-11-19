<?php

namespace App\Service;

/** Wynik bramki jakości (ETAP 10, spec 5.5): co zmierzono i które reguły nie przeszły. */
final class GateResult
{
    /** @param list<string> $failures po jednym czytelnym zdaniu na niespełnioną regułę */
    public function __construct(
        public readonly string $index,
        public readonly string $health,
        public readonly int $indexCount,
        public readonly ?int $catalogCount,
        public readonly float $tolerance,
        public readonly array $failures,
    ) {
    }

    public function passed(): bool
    {
        return $this->failures === [];
    }

    /**
     * Linie do wypisania 1:1 — ostatnia z nich (`GATE=PASS|FAIL`) jest dla
     * skryptów (`tools/es/reindex.sh`), reszta dla człowieka.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        $lines = [sprintf(
            'Bramka %s: zdrowie=%s, dokumenty=%d, catalog=%s, tolerancja=%s',
            $this->index,
            $this->health,
            $this->indexCount,
            $this->catalogCount ?? '?',
            $this->tolerance,
        )];
        foreach ($this->failures as $failure) {
            $lines[] = 'NIESPEŁNIONE: '.$failure;
        }
        $lines[] = 'GATE='.($this->passed() ? 'PASS' : 'FAIL');

        return $lines;
    }
}
