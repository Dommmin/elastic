<?php

namespace App\Tests\Integration\Command;

use App\Command\ReprojectCommand;
use App\Service\CatalogProjectionClient;
use App\Service\ElasticsearchClientFactory;
use App\Service\ProductProjection;
use App\Service\ProjectionUnavailableException;
use Elastic\Elasticsearch\Client;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `search:reproject` na PRAWDZIWYM Elasticsearchu: dokumenty zaindeksowane
 * przed zmianą wersjonowania mają `_version` = stare `sequence` (1..3),
 * a EventTrace w panelu porównuje `_version` z czasem zdarzenia w µs —
 * bez przeliczenia taki produkt wisi jako "nie w ES" na zawsze.
 *
 * Klient projekcji jest dublerem (catalog nie jest tu potrzebny), indeks
 * jednorazowy z mapowaniem products-v1, jak w ElasticsearchIndexerVersioningTest.
 * Bez ES test jest pomijany, nie zielony.
 */
#[Group('integration')]
final class ReprojectCommandTest extends TestCase
{
    private Client $client;
    private string $index;

    protected function setUp(): void
    {
        $host = $this->env('ELASTICSEARCH_HOST');
        if ($host === '') {
            self::markTestSkipped('Brak ELASTICSEARCH_HOST — uruchom w kontenerze search-consumer.');
        }

        $this->client = (new ElasticsearchClientFactory(
            $host,
            $this->env('ELASTICSEARCH_USER'),
            $this->env('ELASTICSEARCH_PASSWORD'),
        ))->create();

        try {
            $this->client->info();
        } catch (\Throwable $e) {
            self::markTestSkipped("Elasticsearch nieosiągalny ({$host}): {$e->getMessage()}");
        }

        $this->index = 'products-reproject-test-'.uniqid();
        $this->client->indices()->create(['index' => $this->index, 'body' => $this->mapping()]);
    }

    protected function tearDown(): void
    {
        if (isset($this->index)) {
            $this->client->indices()->delete(['index' => $this->index, 'ignore_unavailable' => true]);
        }
    }

    public function test_stary_dokument_dostaje_wersje_z_projekcji_a_usuniety_produkt_znika(): void
    {
        $this->indexLegacy('500');
        $this->indexLegacy('501');

        $catalog = $this->createStub(CatalogProjectionClient::class);
        $catalog->method('fetchProductProjection')->willReturnCallback(fn (string $id) => match ($id) {
            '500' => new ProductProjection($this->projection(500), 1790000000000500),
            '501' => new ProductProjection(null, 1790000000000501),
        });

        $tester = $this->reproject($catalog);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Zaindeksowano: 1, nieaktualne (409): 0, usunięte: 1, błędy: 0', $tester->getDisplay());
        self::assertSame(1790000000000500, $this->client->get(['index' => $this->index, 'id' => '500'])->asArray()['_version']);
        self::assertFalse($this->client->exists(['index' => $this->index, 'id' => '501'])->asBool());
    }

    public function test_blad_jednego_produktu_nie_zatrzymuje_reszty_i_daje_kod_bledu(): void
    {
        $this->indexLegacy('500');
        $this->indexLegacy('502');

        $catalog = $this->createStub(CatalogProjectionClient::class);
        $catalog->method('fetchProductProjection')->willReturnCallback(fn (string $id) => match ($id) {
            '500' => new ProductProjection($this->projection(500), 1790000000000500),
            '502' => throw new ProjectionUnavailableException('catalog 503'),
        });

        $tester = $this->reproject($catalog);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Zaindeksowano: 1, nieaktualne (409): 0, usunięte: 0, błędy: 1', $tester->getDisplay());
        self::assertSame(1790000000000500, $this->client->get(['index' => $this->index, 'id' => '500'])->asArray()['_version']);
        self::assertSame(1, $this->client->get(['index' => $this->index, 'id' => '502'])->asArray()['_version']);
    }

    private function reproject(CatalogProjectionClient $catalog): CommandTester
    {
        $tester = new CommandTester(new ReprojectCommand($this->client, $catalog));
        // Paczka 1, żeby przejść przez kilka stron scrolla, a nie jedną.
        $tester->execute(['--alias' => $this->index, '--batch' => '1']);

        return $tester;
    }

    /** Dokument "sprzed zmiany": wersja = sequence zdarzenia, czyli 1. */
    private function indexLegacy(string $id): void
    {
        $document = $this->projection((int) $id);
        unset($document['id'], $document['version'], $document['projected_at_us']);

        $this->client->index([
            'index' => $this->index,
            'id' => $id,
            'body' => $document,
            'version' => 1,
            'version_type' => 'external',
            'refresh' => 'true',
        ]);
    }

    /** @return array<string, mixed> */
    private function projection(int $id): array
    {
        return [
            'id' => $id,
            'projected_at_us' => 1790000000000000,
            'version' => 1,
            'name' => "Produkt {$id}",
            'description' => null,
            'brand' => null,
            'category' => null,
            'attributes' => [],
            'price_min' => 1999,
            'price_max' => 1999,
            'in_stock' => true,
            'rating_avg' => null,
            'rating_count' => 0,
            'offers' => [],
            'created_at' => '2026-10-11T12:00:00+00:00',
            'indexed_at' => '2026-10-11T12:00:00+00:00',
        ];
    }

    /** @return array<string, mixed> */
    private function mapping(): array
    {
        $candidates = [
            '/infra/elasticsearch/mappings/products-v1.json',
            \dirname(__DIR__, 5).'/infra/elasticsearch/mappings/products-v1.json',
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                $body = json_decode(file_get_contents($path), associative: true, flags: \JSON_THROW_ON_ERROR);
                unset($body['_meta']);

                return $body;
            }
        }

        self::markTestSkipped('Brak pliku mapowania products-v1.json.');
    }

    private function env(string $name): string
    {
        return (string) ($_SERVER[$name] ?? getenv($name) ?: '');
    }
}
