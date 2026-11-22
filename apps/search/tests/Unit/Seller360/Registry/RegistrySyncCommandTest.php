<?php

namespace App\Tests\Unit\Seller360\Registry;

use App\Command\Seller360RegistrySyncCommand;
use App\Seller360\Registry\CircuitBreaker;
use App\Seller360\Registry\RegistrySync;
use App\Tests\Support\FakeRedisStore;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Pętla `seller360:registry:sync`: wyjątek przy jednym sprzedawcy (np. odrzucony zapis ES) nie może zabić
 * przejścia. Sprzedawca dostaje ostrzeżenie w logu i próbę zapisu `_meta.status=error`, a pętla idzie dalej.
 */
final class RegistrySyncCommandTest extends TestCase
{
    /** Pierwsza strona: trzech sprzedawców z NIP-em; druga: pusta (koniec `search_after`). */
    private function es(): Client
    {
        $http = new class implements ClientInterface {
            private int $calls = 0;

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $hits = $this->calls++ === 0 ? array_map(static fn (int $id): array => [
                    '_source' => ['seller_id' => (string) $id, 'nip' => "nip-{$id}", 'registry' => ['_meta' => ['updated_at' => "2026-11-2{$id}T00:00:00Z"]]],
                    'sort' => [(string) $id],
                ], [1, 2, 3]) : [];

                return new Response(200, ['X-Elastic-Product' => 'Elasticsearch', 'Content-Type' => 'application/json'], json_encode(['hits' => ['hits' => $hits]]));
            }
        };

        return ClientBuilder::create()->setHosts(['http://es.invalid:9200'])->setHttpClient($http)->build();
    }

    public function test_wyjatek_przy_jednym_sprzedawcy_nie_przerywa_petli(): void
    {
        $synced = [];
        $marked = [];
        $sync = $this->createStub(RegistrySync::class);
        $sync->method('syncSeller')->willReturnCallback(function (int $id) use (&$synced): void {
            $synced[] = $id;
            if ($id === 2) {
                throw new \RuntimeException('ES odrzucił zapis');
            }
        });
        $sync->method('markError')->willReturnCallback(function (int $id, string $nip, string $error) use (&$marked): void {
            $marked[] = [$id, $nip, $error];
        });
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, string, array<string, mixed>}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message, $context];
            }
        };
        $breaker = new CircuitBreaker((new FakeRedisStore)->redis($this));

        $tester = new CommandTester(new Seller360RegistrySyncCommand($sync, $breaker, $this->es(), $logger));
        self::assertSame(0, $tester->execute([]));

        self::assertSame([1, 2, 3], $synced, 'po błędzie sprzedawcy 2 pętla przechodzi do 3');
        self::assertSame([[2, 'nip-2', 'ES odrzucił zapis']], $marked);
        $warnings = array_values(array_filter($logger->records, static fn (array $r): bool => $r[0] === 'warning'));
        self::assertCount(1, $warnings);
        self::assertSame(2, $warnings[0][2]['seller_id']);
        self::assertSame('ES odrzucił zapis', $warnings[0][2]['error']);
        self::assertStringContainsString('sprzedawców: 3', $tester->getDisplay());
    }

    public function test_nieudany_zapis_statusu_error_tez_nie_przerywa_petli(): void
    {
        $synced = [];
        $sync = $this->createStub(RegistrySync::class);
        $sync->method('syncSeller')->willReturnCallback(function (int $id) use (&$synced): void {
            $synced[] = $id;
            throw new \RuntimeException('ES leży');
        });
        $sync->method('markError')->willThrowException(new \RuntimeException('ES dalej leży'));
        $breaker = new CircuitBreaker((new FakeRedisStore)->redis($this));

        $tester = new CommandTester(new Seller360RegistrySyncCommand($sync, $breaker, $this->es(), new \Psr\Log\NullLogger));

        self::assertSame(0, $tester->execute([]));
        self::assertSame([1, 2, 3], $synced);
    }
}
