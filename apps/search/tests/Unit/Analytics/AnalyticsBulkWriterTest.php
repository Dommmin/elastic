<?php

namespace App\Tests\Unit\Analytics;

use App\Analytics\AnalyticsBulkWriter;
use App\Analytics\BulkOutcome;
use Elastic\Elasticsearch\ClientBuilder;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class AnalyticsBulkWriterTest extends TestCase
{
    /** @var list<string> */
    private array $bodies = [];

    public function test_pusta_lista_nie_woła_es(): void
    {
        $outcome = (new AnalyticsBulkWriter($this->esReturning([])))->write([]);

        $this->assertSame([], $this->bodies);
        $this->assertSame(0, $outcome->created);
    }

    public function test_cialo_bulk_uzywa_create_z_indeksem_data_streamu(): void
    {
        $writer = new AnalyticsBulkWriter($this->esReturning(['took' => 1, 'errors' => false, 'items' => [
            ['create' => ['_index' => '.ds-x', '_id' => 'a', 'status' => 201]],
            ['create' => ['_index' => '.ds-y', '_id' => 'b', 'status' => 201]],
        ]]));

        $outcome = $writer->write([
            ['index' => 'events-user-default', 'id' => 'a', 'doc' => ['@timestamp' => 't']],
            ['index' => 'events-impression-default', 'id' => 'b', 'doc' => ['@timestamp' => 't']],
        ]);

        $lines = array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", $this->bodies[0])));
        $this->assertSame(['create' => ['_index' => 'events-user-default', '_id' => 'a']], $lines[0]);
        $this->assertSame(['@timestamp' => 't'], $lines[1]);
        $this->assertSame(['create' => ['_index' => 'events-impression-default', '_id' => 'b']], $lines[2]);
        $this->assertSame(2, $outcome->created);
        $this->assertSame(0, $outcome->duplicates);
    }

    public function test_409_to_duplikat_a_nie_blad(): void
    {
        $writer = new AnalyticsBulkWriter($this->esReturning(['took' => 1, 'errors' => true, 'items' => [
            ['create' => ['_id' => 'a', 'status' => 409, 'error' => ['type' => 'version_conflict_engine_exception']]],
            ['create' => ['_id' => 'b', 'status' => 201]],
        ]]));

        $outcome = $writer->write($this->two());

        $this->assertSame(1, $outcome->created);
        $this->assertSame(1, $outcome->duplicates);
        $this->assertSame([], $outcome->rejected);
        $this->assertSame([], $outcome->retryable);
    }

    public function test_blad_mapowania_to_rejected_z_referencja_do_dokumentu(): void
    {
        $writer = new AnalyticsBulkWriter($this->esReturning(['took' => 1, 'errors' => true, 'items' => [
            ['create' => ['_id' => 'a', 'status' => 400, 'error' => ['type' => 'mapper_parsing_exception', 'reason' => 'zle pole']]],
            ['create' => ['_id' => 'b', 'status' => 201]],
        ]]));

        $outcome = $writer->write($this->two());

        $this->assertSame([BulkOutcome::ref('events-user-default', 'a')], $outcome->rejected);
        $this->assertSame([], $outcome->retryable);
        $this->assertSame(1, $outcome->created);
    }

    public function test_429_i_5xx_to_retryable(): void
    {
        $writer = new AnalyticsBulkWriter($this->esReturning(['took' => 1, 'errors' => true, 'items' => [
            ['create' => ['_id' => 'a', 'status' => 429, 'error' => ['type' => 'es_rejected_execution_exception']]],
            ['create' => ['_id' => 'b', 'status' => 503, 'error' => ['type' => 'unavailable_shards_exception']]],
        ]]));

        $outcome = $writer->write($this->two());

        $this->assertSame([
            BulkOutcome::ref('events-user-default', 'a'),
            BulkOutcome::ref('events-impression-default', 'b'),
        ], $outcome->retryable);
        $this->assertSame([], $outcome->rejected);
    }

    public function test_odpowiedz_z_inna_liczba_pozycji_to_wyjatek(): void
    {
        $writer = new AnalyticsBulkWriter($this->esReturning(['took' => 1, 'errors' => false, 'items' => [
            ['create' => ['_id' => 'a', 'status' => 201]],
        ]]));

        $this->expectException(\UnexpectedValueException::class);
        $writer->write($this->two());
    }

    /** @return list<array{index: string, id: string, doc: array<string, mixed>}> */
    private function two(): array
    {
        return [
            ['index' => 'events-user-default', 'id' => 'a', 'doc' => ['x' => 1]],
            ['index' => 'events-impression-default', 'id' => 'b', 'doc' => ['x' => 2]],
        ];
    }

    /** @param array<string, mixed> $body */
    private function esReturning(array $body): \Elastic\Elasticsearch\Client
    {
        $test = $this;
        $http = new class($body, $test) implements ClientInterface {
            /** @param array<string, mixed> $body */
            public function __construct(private readonly array $body, private readonly AnalyticsBulkWriterTest $test)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->test->record((string) $request->getBody());

                return new Response(200, ['X-Elastic-Product' => 'Elasticsearch', 'Content-Type' => 'application/json'], json_encode($this->body));
            }
        };

        return ClientBuilder::create()->setHosts(['http://es.invalid:9200'])->setHttpClient($http)->build();
    }

    public function record(string $body): void
    {
        $this->bodies[] = $body;
    }
}
