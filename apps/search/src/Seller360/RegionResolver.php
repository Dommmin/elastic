<?php

namespace App\Seller360;

/**
 * Miasto -> województwo z pliku `infra/reference/miasta-wojewodztwa.csv` (`miasto,wojewodztwo`).
 *
 * Porównanie po normalizacji: trim, małe litery, bez polskich znaków (tylko do porównania;
 * zwracamy zawsze pisownię z pliku). Miasto spoza listy to `null` — region jest wtedy pusty,
 * a wywołujący liczy `seller360_region_unknown_total`. Plik czytany leniwie, raz na proces.
 */
class RegionResolver
{
    private const ASCII = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z'];

    /** @var array<string, string>|null */
    private ?array $map = null;

    public function __construct(private readonly string $csvPath)
    {
    }

    public function forCity(string $city): ?string
    {
        return $this->map()[self::normalize($city)] ?? null;
    }

    public function cityCount(): int
    {
        return \count($this->map());
    }

    /** @return list<string> */
    public function regions(): array
    {
        return array_values(array_unique($this->map()));
    }

    private static function normalize(string $city): string
    {
        return strtr(mb_strtolower(trim($city)), self::ASCII);
    }

    /** @return array<string, string> */
    private function map(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        $handle = @fopen($this->csvPath, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Nie można odczytać pliku miast i województw: {$this->csvPath}");
        }

        $map = [];
        try {
            fgetcsv($handle, escape: ''); // nagłówek
            while (($row = fgetcsv($handle, escape: '')) !== false) {
                if (isset($row[0], $row[1]) && trim($row[0]) !== '') {
                    $map[self::normalize($row[0])] = trim($row[1]);
                }
            }
        } finally {
            fclose($handle);
        }

        return $this->map = $map;
    }
}
