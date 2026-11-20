<?php

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * v2 = v1 z JEDNĄ zmianą typu (`rating_avg`: half_float → scaled_float/100).
 * Test pilnuje, żeby nikt przy okazji nie rozjechał reszty mapowania, bo
 * reindeks v1 → v2 zakłada, że dokumenty różnią się tylko tym polem
 * (uzasadnienie i pomiar: docs/POMIARY.md, ETAP 10).
 */
final class MappingV2Test extends TestCase
{
    public function test_v2_rozni_sie_od_v1_dokladnie_jedna_sciezka(): void
    {
        $v1 = $this->load('products-v1.json');
        $v2 = $this->load('products-v2.json');

        self::assertSame(['mappings.properties.rating_avg'], $this->diffPaths($v1, $v2));
        self::assertSame('half_float', $v1['mappings']['properties']['rating_avg']['type']);
        self::assertSame(['type' => 'scaled_float', 'scaling_factor' => 100], $v2['mappings']['properties']['rating_avg']);
    }

    /** @return array<string, mixed> */
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

    /**
     * Ścieżki, pod którymi drzewa się różnią (definicja pola z `type` liczy się
     * jako jeden liść; brakujący klucz lub lista — też).
     *
     * @return list<string>
     */
    private function diffPaths(array $a, array $b, string $prefix = ''): array
    {
        $paths = [];
        foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $key) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (! array_key_exists($key, $a) || ! array_key_exists($key, $b)) {
                $paths[] = $path;
            } elseif (is_array($a[$key]) && is_array($b[$key]) && ! array_is_list($a[$key]) && ! isset($a[$key]['type'], $b[$key]['type'])) {
                array_push($paths, ...$this->diffPaths($a[$key], $b[$key], $path));
            } elseif ($a[$key] !== $b[$key]) {
                $paths[] = $path;
            }
        }

        return $paths;
    }
}
