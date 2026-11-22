<?php

namespace App\Seller360;

/**
 * Filtr kopii publicznej `seller-360-public`: tylko to, co wolno pokazać kupującemu.
 *
 * Biała lista: sekcja spoza listy daje `null` (nie zapisujemy jej wcale), więc nowa sekcja
 * nie wycieknie do indeksu publicznego przez przeoczenie. `billing` i `complaints` nigdy tam nie trafiają.
 * Z `registry` zostaje wyłącznie kurier (`id`, `on_time_rate`, `rated_at`; `rated_at` służy
 * później do straży fan-outu na indeksie publicznym) — bez `legal_name`, `vat_status`, `registered_at`.
 */
final class PublicProjection
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null  `null` = sekcji nie zapisujemy w indeksie publicznym
     */
    public static function filter(string $section, array $data): ?array
    {
        return match ($section) {
            'profile', 'offers', 'sales' => $data,
            'registry' => self::registry($data),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function registry(array $data): array
    {
        $courier = $data['courier'] ?? null;
        if (! \is_array($courier)) {
            return [];
        }

        return ['courier' => array_intersect_key($courier, array_flip(['id', 'on_time_rate', 'rated_at']))];
    }
}
