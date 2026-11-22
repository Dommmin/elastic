<?php

namespace App\Tests\Unit\Seller360;

use App\Seller360\CatalogSellerClient;
use App\Seller360\OffersSectionFlusher;
use App\Seller360\SectionMeta;
use App\Seller360\SectionWriter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class OffersSectionFlusherTest extends TestCase
{
    /** @var array<string, list<string>> */
    private array $sets = [];

    private function redis(): \Redis
    {
        $redis = $this->createStub(\Redis::class);
        $redis->method('exists')->willReturnCallback(fn (string $k) => isset($this->sets[$k]) ? 1 : 0);
        $redis->method('rename')->willReturnCallback(function (string $from, string $to): bool {
            $this->sets[$to] = $this->sets[$from];
            unset($this->sets[$from]);

            return true;
        });
        $redis->method('sMembers')->willReturnCallback(fn (string $k) => $this->sets[$k] ?? []);
        $redis->method('del')->willReturnCallback(function (string $k): int {
            unset($this->sets[$k]);

            return 1;
        });
        $redis->method('sAdd')->willReturnCallback(function (string $k, string $v): int {
            $this->sets[$k][] = $v;

            return 1;
        });

        return $redis;
    }

    public function test_blad_http_dla_jednego_sprzedawcy_wraca_do_zbioru_a_writer_dostaje_tylko_reszte(): void
    {
        $this->sets['seller360:dirty:offers'] = ['7', '9'];
        $client = $this->createStub(CatalogSellerClient::class);
        $client->method('offerStats')->willReturnCallback(function (int $id): array {
            if ($id === 9) {
                throw new \RuntimeException('catalog 503');
            }

            return ['seller_id' => 7, 'active' => 3, 'suspended' => 1, 'sold_out' => 2, 'total' => 6, 'computed_at' => '2026-11-21T10:00:00.123+00:00'];
        });
        $writer = $this->createMock(SectionWriter::class);
        $writer->expects(self::once())->method('applyMany')->with(self::callback(function (array $items): bool {
            return \count($items) === 1
                && $items[0]['sellerId'] === 7
                && $items[0]['section'] === 'offers'
                && $items[0]['data'] === ['active' => 3, 'suspended' => 1, 'sold_out' => 2, 'total' => 6, 'computed_at' => '2026-11-21T10:00:00.123+00:00']
                && $items[0]['meta'] instanceof SectionMeta
                && $items[0]['meta']->version === 1795255200123;
        }))->willReturn(['updated']);

        $written = (new OffersSectionFlusher($this->redis(), $client, $writer, new NullLogger))->flush();

        self::assertSame(1, $written);
        self::assertSame(['9'], $this->sets['seller360:dirty:offers']);
        self::assertArrayNotHasKey('seller360:dirty:offers:flushing', $this->sets);
    }

    public function test_pusty_zbior_nic_nie_robi(): void
    {
        $writer = $this->createMock(SectionWriter::class);
        $writer->expects(self::never())->method('applyMany');

        self::assertSame(0, (new OffersSectionFlusher($this->redis(), $this->createStub(CatalogSellerClient::class), $writer, new NullLogger))->flush());
    }

    public function test_odrzucony_zapis_wraca_do_zbioru(): void
    {
        $this->sets['seller360:dirty:offers'] = ['7'];
        $client = $this->createStub(CatalogSellerClient::class);
        $client->method('offerStats')->willReturn(['seller_id' => 7, 'active' => 1, 'suspended' => 0, 'sold_out' => 0, 'total' => 1, 'computed_at' => '2026-11-21T10:00:00.123+00:00']);
        $writer = $this->createStub(SectionWriter::class);
        $writer->method('applyMany')->willReturn(['error']);

        self::assertSame(0, (new OffersSectionFlusher($this->redis(), $client, $writer, new NullLogger))->flush());
        self::assertSame(['7'], $this->sets['seller360:dirty:offers']);
    }
}
