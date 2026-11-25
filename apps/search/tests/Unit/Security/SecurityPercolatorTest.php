<?php

namespace App\Tests\Unit\Security;

use App\Security\AlertWriter;
use App\Security\SecurityPercolator;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

final class SecurityPercolatorTest extends TestCase
{
    /** @param array<string, mixed> $mget odpowiedź `_mget` */
    private function es(array $mget): Client
    {
        $http = new class($mget) implements ClientInterface {
            /** @param array<string, mixed> $mget */
            public function __construct(private readonly array $mget)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $path = $request->getUri()->getPath();
                $body = str_starts_with($path, '/_data_stream/')
                    ? ['data_streams' => [['indices' => [['index_name' => '.ds-events-security-default-000001']]]]]
                    : (str_ends_with($path, '/_mget') ? $this->mget : ['responses' => []]);

                return new Response(200, ['X-Elastic-Product' => 'Elasticsearch', 'Content-Type' => 'application/json'], json_encode($body));
            }
        };

        return ClientBuilder::create()->setHosts(['http://es.invalid:9200'])->setHttpClient($http)->build();
    }

    public function test_blad_elementu_mget_rzuca_zamiast_udawac_brak_dokumentu(): void
    {
        $client = $this->es(['docs' => [[
            '_index' => '.ds-events-security-default-000001',
            '_id' => 'a',
            'error' => ['type' => 'no_shard_available_action_exception', 'reason' => 'shard niedostępny'],
        ]]]);
        $alerts = $this->createMock(AlertWriter::class);
        $alerts->expects(self::never())->method('write');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('shard niedostępny');

        (new SecurityPercolator($client, $alerts, new NullLogger()))->percolate(['events-security-default/a']);
    }

    public function test_brak_dokumentu_to_nie_blad(): void
    {
        $client = $this->es(['docs' => [['_index' => '.ds-events-security-default-000001', '_id' => 'a', 'found' => false]]]);
        $alerts = $this->createStub(AlertWriter::class);

        self::assertSame(0, (new SecurityPercolator($client, $alerts, new NullLogger()))->percolate(['events-security-default/a']));
    }
}
