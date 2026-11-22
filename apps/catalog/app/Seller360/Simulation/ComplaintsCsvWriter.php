<?php

namespace App\Seller360\Simulation;

use DateTimeImmutable;
use DateTimeInterface;
use Random\Randomizer;
use RuntimeException;

/**
 * Reklamacje syntetycznych sprzedawców jako CSV dla `seller360:complaints:import` (search, Task 9):
 * nagłówek `ticket_id,nip,created_at,category,status,body`, UTF-8, RFC 4180 (pusty `escape`).
 *
 * Wyłączność frazy (podstawa precyzji 1,0): szablony `damage` (słowo „uszkodz…”) dostają tylko
 * zasadzeni i bliskie pudła poza pudłem „complaint”. Pudło „complaint” ma reklamacje, ale wyłącznie
 * z szablonów `other`; około 20% zwykłych sprzedawców też ma 1–3 takie reklamacje. Prawdziwi
 * sprzedawcy nie mają żadnych. Daty z ostatnich 25 dni (sekcja liczy `last_30d`).
 */
final class ComplaintsCsvWriter
{
    public const HEADER = ['ticket_id', 'nip', 'created_at', 'category', 'status', 'body'];

    private const REGULAR_SHARE = 0.2;

    private const STATUSES = ['open', 'in_progress', 'closed'];

    /**
     * @param  array{damage: list<array{category: string, body: string}>, other: list<array{category: string, body: string}>}  $templates
     * @return list<array{days_ago: int, seconds: int, category: string, status: string, body: string}>
     */
    public static function draw(Randomizer $random, ?string $role, array $templates): array
    {
        [$damage, $other] = match (true) {
            $role === null => [0, $random->getFloat(0, 1) < self::REGULAR_SHARE ? $random->getInt(1, 3) : 0],
            PlantedSellers::satisfies($role, 'complaint') => [$random->getInt(1, 2), $random->getInt(0, 2)],
            default => [0, $random->getInt(1, 3)],
        };

        $complaints = [];
        foreach (['damage' => $damage, 'other' => $other] as $kind => $count) {
            for ($i = 0; $i < $count; $i++) {
                $template = $templates[$kind][$random->getInt(0, count($templates[$kind]) - 1)];
                $complaints[] = [
                    'days_ago' => $random->getInt(1, 25),
                    'seconds' => $random->getInt(0, 86_399),
                    'category' => $template['category'],
                    'status' => self::STATUSES[$random->getInt(0, count(self::STATUSES) - 1)],
                    'body' => $template['body'],
                ];
            }
        }

        return $complaints;
    }

    /**
     * @param  list<array{nip: string, complaints: list<array{days_ago: int, seconds: int, category: string, status: string, body: string}>}>  $sellers
     * @return int liczba wierszy
     */
    public static function write(string $path, int $seed, array $sellers, DateTimeImmutable $now): int
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        $handle = fopen($path, 'w');
        if ($handle === false) {
            throw new RuntimeException("Nie można zapisać pliku reklamacji: {$path}");
        }

        $today = $now->setTime(0, 0);
        $rows = 0;
        try {
            fputcsv($handle, self::HEADER, ',', '"', '', "\n");
            foreach ($sellers as $seller) {
                foreach ($seller['complaints'] as $complaint) {
                    $rows++;
                    $createdAt = $today->modify("-{$complaint['days_ago']} days")->modify("+{$complaint['seconds']} seconds");
                    fputcsv($handle, [
                        sprintf('SIM-%d-%07d', $seed, $rows),
                        $seller['nip'],
                        $createdAt->format(DateTimeInterface::ATOM),
                        $complaint['category'],
                        $complaint['status'],
                        $complaint['body'],
                    ], ',', '"', '', "\n");
                }
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }
}
