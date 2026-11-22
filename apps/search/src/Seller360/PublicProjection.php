<?php

namespace App\Seller360;

/**
 * Filtr kopii publicznej `seller-360-public`: tylko to, co wolno pokazać kupującemu.
 *
 * Biała lista: sekcja spoza listy daje `null` (nie zapisujemy jej wcale), więc nowa sekcja
 * nie wycieknie do indeksu publicznego przez przeoczenie. `billing` i `complaints` nigdy tam nie trafiają.
 * Z `registry` zostaje wyłącznie kurier (`id`, `on_time_rate`, `rated_at`; `rated_at` służy
 * później do straży fan-outu na indeksie publicznym) — bez `legal_name`, `vat_status`, `registered_at`.
 * Z `profile` wypada `status_reason` (notatka moderacji), a z pól dokumentu `nip` (ruling R8, obrona w głąb:
 * odczyt widoku sprzedawcy i tak filtruje `_source`, ale czego nie ma w indeksie, to nie wycieknie).
 */
final class PublicProjection
{
    /** Pola dokumentu w kopii publicznej. `synthetic` zostaje, bo purge kasuje kopię publiczną po `synthetic:true`. */
    private const DOC_FIELDS = ['region', 'synthetic'];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null  `null` = sekcji nie zapisujemy w indeksie publicznym
     */
    public static function filter(string $section, array $data): ?array
    {
        return match ($section) {
            'profile' => array_diff_key($data, ['status_reason' => true]),
            'offers', 'sales' => $data,
            'registry' => self::registry($data),
            default => null,
        };
    }

    /**
     * Pola dokumentu (`nip`, `region`, `synthetic`) przycięte do białej listy kopii publicznej. `seller_id` ustawia
     * skrypt zapisu z `_id`, więc go tu nie ma.
     *
     * @param  array<string, mixed>  $docFields
     * @return array<string, mixed>
     */
    public static function filterDoc(array $docFields): array
    {
        return array_intersect_key($docFields, array_flip(self::DOC_FIELDS));
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
