<?php

namespace App\Tests\Integration;

use App\Service\ElasticsearchIndexer;
use App\Service\IndexOutcome;
use App\Tests\Support\RealElasticsearch;
use Elastic\Elasticsearch\Client;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * `ElasticsearchIndexer::bulkSync` (ETAP 10, Task 12b) na PRAWDZIWYM ES: jedna
 * partia `_bulk` z `version_type: external` musi decydować tak samo jak
 * pojedyncze `indexProduct`/`deleteProduct` — 409 to stale (sukces), 404 przy
 * usunięciu to not_found, a usunięcie nieistniejącego zostawia tombstone.
 */
#[Group('integration')]
final class ElasticsearchIndexerBulkSyncTest extends TestCase
{
    use RealElasticsearch;

    private const V = 1_790_000_000_000_000;

    private Client $client;
    private string $index;
    private ElasticsearchIndexer $indexer;

    protected function setUp(): void
    {
        $this->client = $this->elasticsearchOrSkip();
        $this->index = 'products-bulksync-'.uniqid();
        $this->client->indices()->create(['index' => $this->index, 'body' => $this->mappingBody(2)]);
        $this->indexer = new ElasticsearchIndexer($this->client, $this->index);
    }

    protected function tearDown(): void
    {
        if (isset($this->index)) {
            $this->client->indices()->delete(['index' => $this->index, 'ignore_unavailable' => true]);
        }
    }

    public function test_partia_rozroznia_indexed_stale_deleted_i_not_found(): void
    {
        // 2: nowszy dokument ze strumienia; 3: istnieje, catalog go już nie ma.
        self::assertSame(IndexOutcome::Indexed, $this->indexer->indexProduct(['id' => 2] + $this->product(2, 'Ze strumienia'), self::V + 100));
        self::assertSame(IndexOutcome::Indexed, $this->indexer->indexProduct(['id' => 3] + $this->product(3), self::V - 100));

        $result = $this->indexer->bulkSync(
            [$this->projection(1), $this->projection(2)],
            [3, 4],
            self::V,
        );

        self::assertSame(['indexed' => 1, 'stale' => 1, 'deleted' => 1, 'not_found' => 1, 'errors' => 0, 'error_samples' => []], $result);
        self::assertSame(self::V, $this->doc('1')['_version']);
        self::assertArrayNotHasKey('projected_at_us', $this->doc('1')['_source'], 'id/version/projected_at_us nie trafiają do _source (dynamic: strict).');
        self::assertSame('Ze strumienia', $this->doc('2')['_source']['name']);
        self::assertSame(self::V + 100, $this->doc('2')['_version']);
        self::assertFalse($this->client->exists(['index' => $this->index, 'id' => '3'])->asBool());
    }

    public function test_usuniecie_nieistniejacego_zostawia_tombstone(): void
    {
        $result = $this->indexer->bulkSync([], [4], self::V);
        self::assertSame(1, $result['not_found']);

        // Spóźniona starsza projekcja nie może wskrzesić usuniętego produktu.
        self::assertSame(IndexOutcome::Stale, $this->indexer->indexProduct(['id' => 4] + $this->product(4), self::V - 1));
        self::assertFalse($this->client->exists(['index' => $this->index, 'id' => '4'])->asBool());
    }

    public function test_bledy_pozycji_sa_liczone_z_probka_powodow(): void
    {
        $bad = $this->projection(5);
        $bad['pole_spoza_mapowania'] = 'x';

        $result = $this->indexer->bulkSync([$bad, $this->projection(6)], [], self::V);

        self::assertSame(1, $result['indexed']);
        self::assertSame(1, $result['errors']);
        self::assertCount(1, $result['error_samples']);
        self::assertStringStartsWith('5: strict_dynamic_mapping_exception', $result['error_samples'][0]);
    }

    public function test_pusta_partia_nic_nie_wysyla(): void
    {
        self::assertSame(['indexed' => 0, 'stale' => 0, 'deleted' => 0, 'not_found' => 0, 'errors' => 0, 'error_samples' => []], $this->indexer->bulkSync([], [], self::V));
    }

    /** @return array<string, mixed> projekcja w kształcie z catalogu (z id, version, projected_at_us) */
    private function projection(int $id): array
    {
        return ['id' => $id, 'projected_at_us' => self::V, 'version' => 1] + $this->product($id);
    }

    /** @return array<string, mixed> */
    private function doc(string $id): array
    {
        return $this->client->get(['index' => $this->index, 'id' => $id])->asArray();
    }
}
