<?php

namespace App\Tests\Integration\Seller360;

use App\Tests\Support\RealElasticsearch;
use Elastic\Elasticsearch\Client;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Skrypt zapisany `seller360-apply-section` (ETAP 13) na ŻYWYM Elasticsearchu: strażnik wersji
 * sekcji i zapis całej sekcji naraz. Indeks testowy `seller-360-test-<uniqid>` ma mapowanie
 * i analizatory z szablonu `seller-360-v*` (wzorzec szablonu go nie łapie, rola search_service tak).
 *
 * Skrypt musi już leżeć w klastrze (`make seller360-setup`), inaczej test się pomija.
 */
#[Group('integration')]
final class ApplySectionScriptTest extends TestCase
{
    use RealElasticsearch;

    private const SCRIPT = 'seller360-apply-section';
    private const TEMPLATE = '/infra/elasticsearch/seller360/template-seller-360.json';

    private Client $client;
    private string $index;

    protected function setUp(): void
    {
        $this->client = $this->elasticsearchOrSkip();
        if (! is_file(self::TEMPLATE)) {
            self::markTestSkipped('Brak '.self::TEMPLATE.' (montowany w kontenerze search-consumer).');
        }

        $template = json_decode(file_get_contents(self::TEMPLATE), associative: true, flags: \JSON_THROW_ON_ERROR)['template'];
        $this->index = 'seller-360-test-'.uniqid();
        $this->client->indices()->create(['index' => $this->index, 'body' => [
            'settings' => ['number_of_shards' => 1, 'number_of_replicas' => 0, 'analysis' => $template['settings']['analysis']],
            'mappings' => $template['mappings'],
        ]]);
    }

    protected function tearDown(): void
    {
        if (isset($this->index)) {
            $this->client->indices()->delete(['index' => $this->index, 'ignore_unavailable' => true]);
        }
    }

    public function test_upsert_sekcji_billing_w_wersji_5_tworzy_dokument_z_seller_id(): void
    {
        $result = $this->apply('42', 'billing', ['balance_cents' => 1000, 'open_invoices' => 2], 5);

        self::assertSame('created', $result);
        $source = $this->source('42');
        self::assertSame('42', $source['seller_id']);
        self::assertSame(1000, $source['billing']['balance_cents']);
        self::assertSame(5, $source['billing']['_meta']['version']);
        self::assertSame('2026-11-21T10:00:00Z', $source['doc_updated_at']);
    }

    public function test_wersja_4_przy_zapisanej_5_to_noop(): void
    {
        $this->apply('42', 'billing', ['balance_cents' => 1000], 5);

        self::assertSame('noop', $this->apply('42', 'billing', ['balance_cents' => 1], 4));
        self::assertSame('noop', $this->apply('42', 'billing', ['balance_cents' => 1], 5));
        self::assertSame(1000, $this->source('42')['billing']['balance_cents']);
    }

    public function test_wersja_6_zastepuje_sekcje_w_calosci_stare_pole_znika(): void
    {
        $this->apply('42', 'billing', ['balance_cents' => 1000, 'overdue_cents' => 500], 5);

        self::assertSame('updated', $this->apply('42', 'billing', ['balance_cents' => 200], 6));

        $billing = $this->source('42')['billing'];
        self::assertSame(200, $billing['balance_cents']);
        self::assertArrayNotHasKey('overdue_cents', $billing);
        self::assertSame(6, $billing['_meta']['version']);
    }

    public function test_zapis_sekcji_profile_nie_rusza_billing(): void
    {
        $this->apply('42', 'billing', ['balance_cents' => 1000], 5);

        // wersja profilu (1) jest niższa od wersji billingu (5): strażnik działa per sekcja
        self::assertSame('updated', $this->apply('42', 'profile', ['name' => 'Sklep Kowalskiego', 'city' => 'Łódź'], 1));

        $source = $this->source('42');
        self::assertSame(1000, $source['billing']['balance_cents']);
        self::assertSame(5, $source['billing']['_meta']['version']);
        self::assertSame('Łódź', $source['profile']['city']);
    }

    /** @param array<string, mixed> $data @return string `result` z odpowiedzi _update */
    private function apply(string $id, string $section, array $data, int $version): string
    {
        $meta = [
            'version' => $version,
            'source_ts' => '2026-11-21T09:59:00Z',
            'updated_at' => '2026-11-21T10:00:00Z',
            'status' => 'ok',
        ];

        return $this->client->update(['index' => $this->index, 'id' => $id, 'refresh' => 'true', 'body' => [
            'scripted_upsert' => true,
            'upsert' => new \stdClass,
            'script' => ['id' => self::SCRIPT, 'params' => ['section' => $section, 'data' => $data, 'meta' => $meta]],
        ]])->asArray()['result'];
    }

    /** @return array<string, mixed> */
    private function source(string $id): array
    {
        return $this->client->get(['index' => $this->index, 'id' => $id])->asArray()['_source'];
    }
}
