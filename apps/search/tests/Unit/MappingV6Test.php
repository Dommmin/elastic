<?php

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * v6 = v5 + popularność (ETAP 12, spec §7) i NIC więcej: trzy nowe pola
 * i `index.default_pipeline`. Gdyby przy okazji zmieniło się inne pole,
 * migracja v5 → v6 przestałaby być „to samo plus popularność”, a porównanie
 * nDCG przed i po straciłoby sens.
 */
final class MappingV6Test extends TestCase
{
    public function test_v6_to_v5_plus_popularnosc_i_default_pipeline(): void
    {
        $v5 = $this->load('products-v5.json');
        $v6 = $this->load('products-v6.json');

        self::assertSame(['type' => 'rank_feature'], $v6['mappings']['properties']['popularity']);
        self::assertSame(['type' => 'float'], $v6['mappings']['properties']['popularity_raw']);
        self::assertSame(['type' => 'float'], $v6['mappings']['properties']['popularity_ips']);
        self::assertSame(['default_pipeline' => 'products-enrich-popularity'], $v6['settings']['index']);
        self::assertSame('strict', $v6['mappings']['dynamic']);

        unset(
            $v6['mappings']['properties']['popularity'],
            $v6['mappings']['properties']['popularity_raw'],
            $v6['mappings']['properties']['popularity_ips'],
            $v6['settings']['index'],
        );
        self::assertSame($v5, $v6);
    }

    /** @return array<string, mixed> treść pliku bez `_meta` (dokumentacja dla ludzi) */
    private function load(string $file): array
    {
        foreach (['/infra/elasticsearch/mappings/', \dirname(__DIR__, 4).'/infra/elasticsearch/mappings/'] as $dir) {
            if (is_file($dir.$file)) {
                $body = json_decode(file_get_contents($dir.$file), associative: true, flags: \JSON_THROW_ON_ERROR);
                unset($body['_meta']);

                return $body;
            }
        }

        self::fail("Brak pliku mapowania {$file}.");
    }
}
