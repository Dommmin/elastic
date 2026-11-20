<?php

namespace App\Analytics;

/**
 * Wygładzony współczynnik popularności (ETAP 12, spec §6) — nie suma, bo suma
 * rosłaby z ekspozycją i wygrywałyby produkty z częstych fraz:
 *
 * - surowa: `(kliknięcia + α·c̄) / (wyświetlenia + α)`
 * - IPS:    `(Σ 1/p(k) + α·c̄) / (wyświetlenia + α)`
 *
 * `c̄` (globalny CTR okna) i `α` > 0, więc wynik zawsze > 0 — wymóg `rank_feature`.
 * Produkt bez danych dostaje dokładnie `c̄`; im więcej wyświetleń, tym bliżej
 * własnego CTR. Liczy ją ES (`bucket_script` transformu z `bucketScript()`);
 * wersja PHP to wzorzec dla testów — te same parametry, to samo wyrażenie.
 */
final class PopularityFormula
{
    private const SCRIPT = '(params.clicks + params.alpha * params.mean_ctr) / (params.impressions + params.alpha)';

    public function __construct(
        public readonly float $meanCtr,
        public readonly float $alpha,
    ) {
        if ($meanCtr <= 0.0 || $alpha <= 0.0) {
            throw new \InvalidArgumentException("c̄ i α muszą być > 0 (c̄={$meanCtr}, α={$alpha}) — inaczej popularność może wyjść 0, a rank_feature jej nie przyjmie.");
        }
    }

    public function raw(int $clicks, int $impressions): float
    {
        return $this->score((float) $clicks, $impressions);
    }

    public function ips(float $weightedClicks, int $impressions): float
    {
        return $this->score($weightedClicks, $impressions);
    }

    /**
     * `bucket_script` pivotu: licznik z `$clicksPath`, wyświetlenia z filtra `impressions`.
     *
     * @return array<string, mixed>
     */
    public function bucketScript(string $clicksPath): array
    {
        return [
            'buckets_path' => ['clicks' => $clicksPath, 'impressions' => 'impressions._count'],
            'script' => [
                'lang' => 'painless',
                'source' => self::SCRIPT,
                'params' => ['alpha' => $this->alpha, 'mean_ctr' => $this->meanCtr],
            ],
        ];
    }

    private function score(float $clicks, int $impressions): float
    {
        return ($clicks + $this->alpha * $this->meanCtr) / ($impressions + $this->alpha);
    }
}
