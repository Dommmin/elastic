<?php

namespace App\Tests\Integration\Seller360;

use App\Seller360\CatalogSellerClient;
use App\Seller360\Rebuild\BillingParity;
use App\Seller360\SectionMeta;
use App\Seller360\SectionWriter;
use App\Seller360\Seller360Metrics;
use App\Tests\Support\RealElasticsearch;
use Elastic\Elasticsearch\Client;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Zgodność sekcji `billing` z `/api/internal/billing/accounts` (dowód 0 rozjazdów po przebudowie pod obciążeniem).
 */
#[Group('integration')]
final class BillingParityTest extends TestCase
{
    use RealElasticsearch;

    private const DIR = '/infra/elasticsearch/seller360';

    private Client $client;
    private string $index;

    protected function setUp(): void
    {
        $this->client = $this->elasticsearchOrSkip();
        if (! is_dir(self::DIR)) {
            self::markTestSkipped('Brak '.self::DIR.' (montowany w kontenerze search).');
        }
        $this->index = 'seller-360-test-'.uniqid();
        $template = json_decode(file_get_contents(self::DIR.'/template-seller-360.json'), associative: true, flags: \JSON_THROW_ON_ERROR)['template'];
        $this->client->indices()->create(['index' => $this->index, 'body' => [
            'settings' => ['number_of_shards' => 1, 'number_of_replicas' => 0, 'analysis' => $template['settings']['analysis']],
            'mappings' => $template['mappings'],
        ]]);
        $writer = new SectionWriter($this->client, new Seller360Metrics($this->createStub(\Redis::class), new NullLogger), $this->index, $this->index.'-brak-publicznego', refresh: true);
        $meta = static fn (int $v): SectionMeta => new SectionMeta($v, new \DateTimeImmutable('2026-11-21T10:00:00Z'));
        $writer->apply(1, 'billing', ['balance_cents' => 100, 'overdue_cents' => 0, 'overdue_since' => null, 'open_invoices' => 1, 'last_invoice_id' => 'A'], $meta(4));
        $writer->apply(2, 'billing', ['balance_cents' => 200, 'overdue_cents' => 0, 'overdue_since' => null, 'open_invoices' => 1, 'last_invoice_id' => 'B'], $meta(2));
        $writer->apply(3, 'billing', ['balance_cents' => 300, 'overdue_cents' => 0, 'overdue_since' => null, 'open_invoices' => 1, 'last_invoice_id' => 'C'], $meta(7));
    }

    protected function tearDown(): void
    {
        if (isset($this->index)) {
            $this->client->indices()->delete(['index' => $this->index, 'ignore_unavailable' => true]);
        }
    }

    public function test_porownuje_wersje_i_pola_snapshotu_wszystkich_kont_stronicowanych(): void
    {
        $catalog = new class('http://catalog.invalid') extends CatalogSellerClient {
            /** @var list<int> */
            public array $afters = [];

            public function billingAccounts(int $after, int $limit): array
            {
                $this->afters[] = $after;
                $all = [
                    ['seller_id' => 1, 'balance_cents' => 100, 'overdue_cents' => 0, 'overdue_since' => null, 'open_invoices' => 1, 'version' => 4],
                    ['seller_id' => 2, 'balance_cents' => 250, 'overdue_cents' => 0, 'overdue_since' => null, 'open_invoices' => 2, 'version' => 3],
                    ['seller_id' => 3, 'balance_cents' => 999, 'overdue_cents' => 0, 'overdue_since' => null, 'open_invoices' => 1, 'version' => 7],
                    ['seller_id' => 4, 'balance_cents' => 5, 'overdue_cents' => 0, 'overdue_since' => null, 'open_invoices' => 1, 'version' => 1],
                ];
                $page = array_values(array_filter($all, static fn (array $r): bool => $r['seller_id'] > $after));
                $page = \array_slice($page, 0, $limit);

                return ['items' => $page, 'next_after' => \count($page) === $limit ? $page[$limit - 1]['seller_id'] : null];
            }
        };

        $result = (new BillingParity($this->client, $catalog, $this->index, pageSize: 2))->check();

        self::assertSame(4, $result['accounts']);
        self::assertSame([0, 2, 4], $catalog->afters);
        self::assertCount(3, $result['mismatches']);
        $all = implode("\n", $result['mismatches']);
        self::assertStringContainsString('2: wersja catalog 3, seller-360 2', $all);
        self::assertStringContainsString('3: balance_cents', $all, 'ta sama wersja, inne pola');
        self::assertStringContainsString('4: brak sekcji billing', $all);
    }
}
