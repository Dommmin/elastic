<?php

namespace App\Seller360;

/**
 * Dokument `seller-360` przełożony na props ekranu konsultanta. Każda z sześciu sekcji jest
 * osobnym blokiem: brak sekcji to stan poprawny (dokument rośnie z sekcji, które przyszły),
 * więc zamiast dziury idzie napis „brak danych z <źródło>”.
 */
final class Seller360Card
{
    /** Sekcja → nazwa źródła w napisie o braku danych (kolejność = kolejność na karcie). */
    public const SOURCES = [
        'profile' => 'catalog',
        'offers' => 'oferty',
        'sales' => 'analityka',
        'billing' => 'billing',
        'complaints' => 'reklamacje',
        'registry' => 'rejestr',
    ];

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    public static function fromSource(int $sellerId, array $source): array
    {
        $sections = [];
        foreach (self::SOURCES as $key => $label) {
            $section = is_array($source[$key] ?? null) ? $source[$key] : null;
            $meta = is_array($section['_meta'] ?? null) ? $section['_meta'] : null;
            unset($section['_meta']);

            $sections[] = [
                'key' => $key,
                'source' => $label,
                'present' => $section !== null,
                'missing_label' => 'brak danych z '.$label,
                'data' => $section ?? (object) [],
                'meta' => $meta,
            ];
        }

        return [
            'id' => $sellerId,
            'nip' => $source['nip'] ?? null,
            'region' => $source['region'] ?? null,
            'synthetic' => (bool) ($source['synthetic'] ?? false),
            'name' => $source['profile']['name'] ?? null,
            'doc_updated_at' => $source['doc_updated_at'] ?? null,
            'sections' => $sections,
        ];
    }

    /**
     * Wiersz listy wyników: tylko pola potrzebne w tabeli, null tam, gdzie sekcji brak.
     *
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    public static function row(int $sellerId, array $source): array
    {
        $stale = [];
        foreach (array_keys(self::SOURCES) as $key) {
            $status = $source[$key]['_meta']['status'] ?? null;
            if ($status !== null && $status !== 'ok') {
                $stale[] = $key;
            }
        }

        return [
            'id' => $sellerId,
            'name' => $source['profile']['name'] ?? null,
            'nip' => $source['nip'] ?? null,
            'city' => $source['profile']['city'] ?? null,
            'region' => $source['region'] ?? null,
            'gmv_30d_cents' => $source['sales']['gmv_30d_cents'] ?? null,
            'conversion_drop' => $source['sales']['conversion_drop'] ?? null,
            'overdue_cents' => $source['billing']['overdue_cents'] ?? null,
            'complaints_open' => $source['complaints']['open'] ?? null,
            'vat_status' => $source['registry']['vat_status'] ?? null,
            'courier' => $source['registry']['courier']['id'] ?? null,
            'not_ok_sections' => $stale,
        ];
    }
}
