<?php

namespace App\Seller360\Rebuild;

use App\Seller360\CatalogSellerClient;
use Elastic\Elasticsearch\Client;

/**
 * Zgodność sekcji `billing` w seller-360 ze stanem kont w catalogu (`/api/internal/billing/accounts`, wszystkie
 * strony). Dowód „0 rozjazdów" po przebudowie pod obciążeniem (`tools/seller360/rebuild-under-load.sh`).
 *
 * Konto zgodne: dokument ma sekcję o tej samej `_meta.version` co konto i te same pola snapshotu
 * (`balance_cents`, `overdue_cents`, `overdue_since`, `open_invoices`). Uruchamiać po wygaszeniu ruchu
 * (zdarzenie w locie to chwilowy rozjazd wersji).
 */
class BillingParity
{
    private const FIELDS = ['balance_cents', 'overdue_cents', 'overdue_since', 'open_invoices'];

    public function __construct(
        private readonly Client $es,
        private readonly CatalogSellerClient $catalog,
        private readonly string $index = 'seller-360',
        private readonly int $pageSize = 2000,
    ) {
    }

    /** @return array{accounts: int, mismatches: list<string>} */
    public function check(): array
    {
        $accounts = 0;
        $mismatches = [];
        $after = 0;
        do {
            $page = $this->catalog->billingAccounts($after, $this->pageSize);
            $rows = $page['items'];
            $accounts += \count($rows);
            $docs = $rows === [] ? [] : $this->es->mget([
                'index' => $this->index,
                '_source_includes' => 'billing',
                'body' => ['ids' => array_map(static fn (array $r): string => (string) $r['seller_id'], $rows)],
            ])->asArray()['docs'];
            $billing = [];
            foreach ($docs as $doc) {
                $billing[(int) $doc['_id']] = ($doc['found'] ?? false) ? ($doc['_source']['billing'] ?? null) : null;
            }
            foreach ($rows as $row) {
                $id = (int) $row['seller_id'];
                $section = $billing[$id] ?? null;
                if ($section === null) {
                    $mismatches[] = "{$id}: brak sekcji billing";

                    continue;
                }
                $version = $section['_meta']['version'] ?? null;
                if ($version !== (int) $row['version']) {
                    $mismatches[] = "{$id}: wersja catalog {$row['version']}, seller-360 ".json_encode($version);

                    continue;
                }
                foreach (self::FIELDS as $field) {
                    if (($section[$field] ?? null) !== ($row[$field] ?? null)) {
                        $mismatches[] = "{$id}: {$field} catalog ".json_encode($row[$field] ?? null).', seller-360 '.json_encode($section[$field] ?? null);
                    }
                }
            }
            $after = $page['next_after'];
        } while ($after !== null);

        return ['accounts' => $accounts, 'mismatches' => $mismatches];
    }
}
