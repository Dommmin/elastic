<?php

namespace App\Tests\Unit\Seller360;

use App\Seller360\Rebuild\WriteTargets;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WriteTargetsTest extends TestCase
{
    /** @var array<string, string> */
    private array $store = [];

    private int $gets = 0;

    private bool $down = false;

    private float $clock = 1000.0;

    private function redis(): \Redis
    {
        $redis = $this->createStub(\Redis::class);
        $redis->method('get')->willReturnCallback(function (string $k) {
            if ($this->down) {
                throw new \RedisException('Connection refused');
            }
            $this->gets++;

            return $this->store[$k] ?? false;
        });
        $redis->method('set')->willReturnCallback(function (string $k, string $v): bool {
            $this->store[$k] = $v;

            return true;
        });
        $redis->method('del')->willReturnCallback(function (string $k): int {
            unset($this->store[$k]);

            return 1;
        });

        return $redis;
    }

    private function targets(): WriteTargets
    {
        return new WriteTargets($this->redis(), new NullLogger, fn (): float => $this->clock);
    }

    public function test_bez_klucza_w_redisie_pisze_do_aliasow_zapisu(): void
    {
        self::assertSame([['main' => 'seller-360-write', 'public' => 'seller-360-public-write']], $this->targets()->current());
    }

    public function test_start_dual_dopisuje_pare_nowych_indeksow_a_finish_wraca_do_jednej_pary(): void
    {
        $targets = $this->targets();
        $targets->startDual('seller-360-v2', 'seller-360-public-v2');

        self::assertSame([
            ['main' => 'seller-360-write', 'public' => 'seller-360-public-write'],
            ['main' => 'seller-360-v2', 'public' => 'seller-360-public-v2'],
        ], $targets->current());
        self::assertSame(
            [['main' => 'seller-360-write', 'public' => 'seller-360-public-write'], ['main' => 'seller-360-v2', 'public' => 'seller-360-public-v2']],
            json_decode($this->store[WriteTargets::KEY], true),
            'lista leży w Redisie, więc widzą ją inne procesy',
        );

        $targets->finish('seller-360-write', 'seller-360-public-write');
        self::assertSame([['main' => 'seller-360-write', 'public' => 'seller-360-public-write']], $targets->current());
    }

    public function test_inny_proces_widzi_zmiane_najpozniej_po_sekundzie(): void
    {
        $consumer = $this->targets();
        self::assertCount(1, $consumer->current());

        $this->targets()->startDual('seller-360-v2', 'seller-360-public-v2');
        $this->clock += 0.5;
        self::assertCount(1, $consumer->current(), 'w ciągu sekundy cache w pamięci');

        $this->clock += 0.6;
        self::assertCount(2, $consumer->current(), 'po sekundzie świeży odczyt z Redisa');
    }

    public function test_cache_oszczedza_redis_w_obrebie_sekundy(): void
    {
        $targets = $this->targets();
        for ($i = 0; $i < 50; $i++) {
            $targets->current();
        }

        self::assertSame(1, $this->gets);
    }

    public function test_awaria_redisa_zostawia_ostatnia_znana_liste(): void
    {
        $targets = $this->targets();
        $targets->startDual('seller-360-v2', 'seller-360-public-v2');
        $this->clock += 2;
        $this->down = true;

        self::assertCount(2, $targets->current(), 'podwójny zapis nie może zniknąć po cichu przez chwilową awarię Redisa');
    }

    public function test_awaria_redisa_bez_znanej_listy_konczy_sie_wyjatkiem(): void
    {
        $this->down = true;

        $this->expectException(\RedisException::class);
        $this->targets()->current();
    }

    public function test_uszkodzona_lista_w_redisie_to_wyjatek_a_nie_cichy_powrot_do_aliasow(): void
    {
        $this->store[WriteTargets::KEY] = '{"main":"x"}';

        $this->expectException(\UnexpectedValueException::class);
        $this->targets()->current();
    }

    public function test_aliasy_domyslne_sa_konfigurowalne(): void
    {
        $targets = new WriteTargets($this->redis(), new NullLogger, fn (): float => $this->clock, 'rt:targets', 'rt-main-write', 'rt-public-write');

        self::assertSame([['main' => 'rt-main-write', 'public' => 'rt-public-write']], $targets->current());
        self::assertSame(['rt-main-write', 'rt-public-write'], $targets->indices());
    }
}
