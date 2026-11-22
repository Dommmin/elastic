<?php

namespace App\Seller360;

/**
 * Wspólne mapowanie stanu z catalogu na sekcje seller-360: to samo dla zdarzeń (`seller.*`, `billing.*`
 * w {@see \App\MessageHandler\Seller360EventHandler}) i dla backfillu przebudowy (`/api/internal/sellers`,
 * `/api/internal/billing/accounts`), żeby obie drogi dawały identyczne sekcje przy tej samej wersji.
 */
final class SectionPayloads
{
    /**
     * Sekcja `profile` i pola dokumentu (`nip`, `region` z miasta, `synthetic`).
     *
     * @param  array<string, mixed>  $d  ładunek `seller.*` albo wiersz `/api/internal/sellers`
     * @return array{data: array<string, mixed>, doc: array{nip: mixed, region: ?string, synthetic: bool}}
     */
    public static function profile(array $d, RegionResolver $regions): array
    {
        return [
            'data' => [
                'name' => $d['name'] ?? null,
                'city' => $d['city'] ?? null,
                'status' => $d['status'] ?? null,
                'status_reason' => $d['status_reason'] ?? null,
            ],
            'doc' => [
                'nip' => $d['nip'] ?? null,
                'region' => $regions->forCity((string) ($d['city'] ?? '')),
                'synthetic' => (bool) ($d['synthetic'] ?? false),
            ],
        ];
    }

    /**
     * Sekcja `billing` ze snapshotu salda. `last_invoice_id` niesie tylko zdarzenie (`invoice_id`);
     * snapshot z API go nie ma, więc backfill zapisuje `null`.
     *
     * @param  array<string, mixed>  $d  ładunek `billing.*` albo wiersz `/api/internal/billing/accounts`
     * @return array<string, mixed>
     */
    public static function billing(array $d): array
    {
        return [
            'balance_cents' => $d['balance_cents'] ?? 0,
            'overdue_cents' => $d['overdue_cents'] ?? 0,
            'overdue_since' => $d['overdue_since'] ?? null,
            'open_invoices' => $d['open_invoices'] ?? 0,
            'last_invoice_id' => isset($d['invoice_id']) ? (string) $d['invoice_id'] : null,
        ];
    }
}
