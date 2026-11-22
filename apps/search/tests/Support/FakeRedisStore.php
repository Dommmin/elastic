<?php

namespace App\Tests\Support;

/**
 * Pamięciowy Redis dla testów jednostkowych: tylko `get/set/incr/del/expire` (bez TTL — wygasanie
 * sprawdzamy asercją na argumentach `set`, nie zegarem).
 */
final class FakeRedisStore
{
    /** @var array<string, string> */
    public array $data = [];

    /** @var array<string, mixed> ostatnie opcje `set` per klucz */
    public array $setOptions = [];

    public function redis(\PHPUnit\Framework\TestCase $case): \Redis
    {
        $redis = (new \ReflectionMethod($case, 'createStub'))->invoke($case, \Redis::class);
        $redis->method('get')->willReturnCallback(fn (string $k) => $this->data[$k] ?? false);
        $redis->method('set')->willReturnCallback(function (string $k, mixed $v, mixed $opt = null): bool {
            $this->data[$k] = (string) $v;
            $this->setOptions[$k] = $opt;

            return true;
        });
        $redis->method('incr')->willReturnCallback(function (string $k): int {
            return (int) ($this->data[$k] = (string) ((int) ($this->data[$k] ?? 0) + 1));
        });
        $redis->method('del')->willReturnCallback(function (string ...$ks): int {
            foreach ($ks as $k) {
                unset($this->data[$k]);
            }

            return \count($ks);
        });
        $redis->method('expire')->willReturn(true);

        return $redis;
    }
}
