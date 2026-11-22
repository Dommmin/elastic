<?php

namespace App\Tests\Unit\Seller360\Registry;

use App\Seller360\Registry\RegistryClient;
use App\Seller360\Registry\RegistryUnavailable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RegistryClientTest extends TestCase
{
    /** @var list<int> */
    private array $sleeps = [];

    private function client(callable $responder): RegistryClient
    {
        return new RegistryClient('http://registry.invalid', new MockHttpClient($responder), function (int $ms): void {
            $this->sleeps[] = $ms;
        });
    }

    public function test_dwa_503_i_sukces_z_backoffem_200_i_800_ms(): void
    {
        $calls = 0;
        $client = $this->client(function () use (&$calls): MockResponse {
            return ++$calls < 3
                ? new MockResponse('', ['http_code' => 503])
                : new MockResponse('{"courier_id":"courier-4"}', ['response_headers' => ['content-type: application/json']]);
        });

        self::assertSame('courier-4', $client->courierAssignment('5260250274'));
        self::assertSame(3, $calls);
        self::assertSame([200, 800], $this->sleeps);
    }

    public function test_trzy_porazki_rzucaja_registry_unavailable(): void
    {
        $calls = 0;
        $client = $this->client(function () use (&$calls): MockResponse {
            $calls++;

            return new MockResponse('', ['http_code' => 503]);
        });

        $this->expectException(RegistryUnavailable::class);
        try {
            $client->company('5260250274');
        } finally {
            self::assertSame(3, $calls);
        }
    }

    public function test_blad_transportu_to_registry_unavailable(): void
    {
        $client = $this->client(fn () => new MockResponse('', ['error' => 'timeout']));

        $this->expectException(RegistryUnavailable::class);
        $client->company('5260250274');
    }
}
