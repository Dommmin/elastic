<?php

namespace App\Tests\Unit;

use App\Command\CreateSearchIndexCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * v3, v4 i v5 = v2 bez żadnej zmiany mapowania (ETAP 10: ćwiczenia przerwania,
 * drogi B i aborta na prawdziwej przebudowie). Różnią się wyłącznie `_meta` —
 * gdyby ktoś przy okazji zmienił pole, reindeks vN−1 → vN przestałby być „tym
 * samym indeksem od nowa”, a pomiary z Task 12/12c straciłyby sens.
 */
final class MappingRebuildTest extends TestCase
{
    /** @return iterable<string, array{int, string}> */
    public static function przebudowy(): iterable
    {
        yield 'v3' => [3, 'przebudowa bez zmiany mapowania (ETAP 10: ćwiczenie przerwania)'];
        yield 'v4' => [4, 'przebudowa bez zmiany mapowania (ETAP 10: ćwiczenie drogi B i aborta)'];
        yield 'v5' => [5, 'przebudowa bez zmiany mapowania (ETAP 10: ćwiczenie drogi B i aborta)'];
    }

    #[DataProvider('przebudowy')]
    public function test_przebudowa_jest_identyczna_z_v2_poza_meta(int $version, string $description): void
    {
        $v2 = $this->load('products-v2.json');
        $vN = $this->load("products-v{$version}.json");

        self::assertNotSame($v2['_meta'], $vN['_meta']);
        self::assertStringContainsString($description, $vN['_meta']['description']);
        unset($v2['_meta'], $vN['_meta']);
        self::assertSame($v2, $vN);
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
