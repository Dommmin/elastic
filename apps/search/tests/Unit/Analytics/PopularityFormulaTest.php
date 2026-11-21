<?php

namespace App\Tests\Unit\Analytics;

use App\Analytics\PopularityFormula;
use PHPUnit\Framework\TestCase;

final class PopularityFormulaTest extends TestCase
{
    public function test_zero_klikniec_i_wyswietlen_daje_srednia_globalna(): void
    {
        $formula = new PopularityFormula(meanCtr: 0.05, alpha: 20);

        self::assertEqualsWithDelta(0.05, $formula->raw(0, 0), 1e-12);
        self::assertEqualsWithDelta(0.05, $formula->ips(0.0, 0), 1e-12);
        self::assertGreaterThan(0, $formula->raw(0, 0));
    }

    public function test_surowa_i_ips(): void
    {
        $formula = new PopularityFormula(meanCtr: 0.15, alpha: 20);

        // (1 + 20·0.15) / (10 + 20) vs (1/0.5 + 20·0.15) / (10 + 20)
        self::assertEqualsWithDelta(4 / 30, $formula->raw(1, 10), 1e-12);
        self::assertEqualsWithDelta(5 / 30, $formula->ips(2.0, 10), 1e-12);
    }

    public function test_duza_ekspozycja_zbiega_do_wlasnego_ctr(): void
    {
        $formula = new PopularityFormula(meanCtr: 0.05, alpha: 20);

        self::assertEqualsWithDelta(0.3, $formula->raw(300_000, 1_000_000), 1e-4);
    }

    public function test_bez_klikniec_wartosc_dalej_dodatnia(): void
    {
        // rank_feature przyjmuje tylko > 0 — produkt bez kliknięć nie wypada z rankingu.
        $formula = new PopularityFormula(meanCtr: 0.05, alpha: 20);

        self::assertGreaterThan(0, $formula->raw(0, 5000));
    }

    public function test_bucket_script_licze_to_samo_co_php(): void
    {
        $formula = new PopularityFormula(meanCtr: 0.15, alpha: 20);
        $script = $formula->bucketScript('clicks_ips');

        self::assertSame(['clicks' => 'clicks_ips', 'impressions' => 'impressions._count'], $script['buckets_path']);
        self::assertSame(['alpha' => 20.0, 'mean_ctr' => 0.15], $script['script']['params']);
        self::assertStringContainsString('params.clicks + params.alpha * params.mean_ctr', $script['script']['source']);
    }

    public function test_c_i_alfa_musza_byc_dodatnie(): void
    {
        foreach ([[0.0, 20.0], [0.05, 0.0], [-0.1, 20.0]] as [$c, $a]) {
            try {
                new PopularityFormula($c, $a);
                self::fail("przyjęto c̄={$c}, α={$a}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
