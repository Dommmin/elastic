<?php

namespace App\Tests\Unit;

use App\Command\CreateSearchIndexCommand;
use PHPUnit\Framework\TestCase;

/**
 * v5 = v2 bez żadnej zmiany mapowania (ETAP 10: ćwiczenie drogi B i aborta
 * migracji na prawdziwej przebudowie). Różni się wyłącznie `_meta` — gdyby ktoś
 * przy okazji zmienił pole, reindeks v4 → v5 przestałby być „tym samym indeksem
 * od nowa”, a pomiary z Task 12c straciłyby sens.
 */
final class MappingV5Test extends TestCase
{
    public function test_v5_jest_identyczne_z_v2_poza_meta(): void
    {
        $v2 = $this->load('products-v2.json');
        $v5 = $this->load('products-v5.json');

        self::assertNotSame($v2['_meta'], $v5['_meta']);
        self::assertStringContainsString('przebudowa bez zmiany mapowania (ETAP 10: ćwiczenie drogi B i aborta)', $v5['_meta']['description']);
        unset($v2['_meta'], $v5['_meta']);
        self::assertSame($v2, $v5);
    }

    public function test_swiezy_klaster_startuje_od_ostatniej_merytorycznej_wersji(): void
    {
        self::assertSame(2, CreateSearchIndexCommand::CURRENT_VERSION);
    }

    /** @return array<string, mixed> */
    private function load(string $file): array
    {
        foreach (['/infra/elasticsearch/mappings/', \dirname(__DIR__, 4).'/infra/elasticsearch/mappings/'] as $dir) {
            if (is_file($dir.$file)) {
                return json_decode(file_get_contents($dir.$file), associative: true, flags: \JSON_THROW_ON_ERROR);
            }
        }

        self::fail("Brak pliku mapowania {$file}.");
    }
}
