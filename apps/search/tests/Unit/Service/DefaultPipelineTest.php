<?php

namespace App\Tests\Unit\Service;

use App\Service\DefaultPipeline;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * `index.default_pipeline` w pliku mapowania musi wskazywać istniejący pipeline,
 * inaczej ES odrzuca KAŻDY zapis do nowego indeksu (strumień, `_reindex`, backfill).
 */
final class DefaultPipelineTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    public function test_nazwa_z_settings_index_i_z_plaskiego_settings(): void
    {
        self::assertSame('p1', DefaultPipeline::of(['settings' => ['index' => ['default_pipeline' => 'p1']]]));
        self::assertSame('p2', DefaultPipeline::of(['settings' => ['default_pipeline' => 'p2']]));
        self::assertSame('p3', DefaultPipeline::of(['settings' => ['index.default_pipeline' => 'p3']]));
        self::assertNull(DefaultPipeline::of(['settings' => ['number_of_shards' => 1]]));
        self::assertNull(DefaultPipeline::of([]));
        // `_none` to jawne „bez pipeline'u” w ES — nie ma czego sprawdzać.
        self::assertNull(DefaultPipeline::of(['settings' => ['index' => ['default_pipeline' => '_none']]]));
    }

    public function test_brak_pipelinea_404_zwraca_jego_nazwe(): void
    {
        $missing = DefaultPipeline::missing($this->esAnswering(404, []), ['settings' => ['index' => ['default_pipeline' => 'products-enrich-popularity']]]);

        self::assertSame('products-enrich-popularity', $missing);
        self::assertSame(['/_ingest/pipeline/products-enrich-popularity'], $this->paths);
    }

    public function test_istniejacy_pipeline_zwraca_null(): void
    {
        $missing = DefaultPipeline::missing($this->esAnswering(200, ['products-enrich-popularity' => ['processors' => []]]), ['settings' => ['default_pipeline' => 'products-enrich-popularity']]);

        self::assertNull($missing);
    }

    public function test_mapowanie_bez_pipelinea_nie_pyta_es(): void
    {
        self::assertNull(DefaultPipeline::missing($this->esAnswering(500, []), ['settings' => []]));
        self::assertSame([], $this->paths);
    }

    /** @param array<string, mixed> $body */
    private function esAnswering(int $status, array $body): Client
    {
        $test = $this;
        $http = new class($status, $body, $test) implements ClientInterface {
            /** @param array<string, mixed> $body */
            public function __construct(private readonly int $status, private readonly array $body, private readonly DefaultPipelineTest $test)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->test->record($request->getUri()->getPath());

                return new Response($this->status, ['X-Elastic-Product' => 'Elasticsearch', 'Content-Type' => 'application/json'], json_encode((object) $this->body));
            }
        };

        return ClientBuilder::create()->setHosts(['http://es.invalid:9200'])->setHttpClient($http)->build();
    }

    public function record(string $path): void
    {
        $this->paths[] = $path;
    }
}
