<?php

namespace Tests\Support;

use Closure;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Sztuczny transport PSR-18 dla klienta ES: testy bez żywego klastra.
 * `$handler(RequestInterface): array{0: int, 1: array}` zwraca status i ciało JSON;
 * wyjątek z handlera udaje awarię transportu. Każde żądanie ląduje w `$log` jako „GET /ścieżka”.
 */
final class FakeEsTransport
{
    /** @var list<string> */
    public array $log = [];

    /** @param  Closure(RequestInterface): array{0: int, 1: array<string, mixed>}  $handler */
    public function __construct(private readonly Closure $handler) {}

    public function client(): Client
    {
        $self = $this;

        return ClientBuilder::create()
            ->setHosts(['http://es.invalid:9200'])
            ->setHttpClient(new class($self) implements ClientInterface
            {
                public function __construct(private readonly FakeEsTransport $owner) {}

                public function sendRequest(RequestInterface $request): ResponseInterface
                {
                    return $this->owner->handle($request);
                }
            })
            ->build();
    }

    public function handle(RequestInterface $request): ResponseInterface
    {
        $this->log[] = $request->getMethod().' '.$request->getUri()->getPath();
        [$status, $json] = ($this->handler)($request);

        return new Response($status, ['X-Elastic-Product' => 'Elasticsearch', 'Content-Type' => 'application/json'], json_encode($json));
    }
}
