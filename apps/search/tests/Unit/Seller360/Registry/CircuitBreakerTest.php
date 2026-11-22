<?php

namespace App\Tests\Unit\Seller360\Registry;

use App\Seller360\Registry\CircuitBreaker;
use App\Tests\Support\FakeRedisStore;
use PHPUnit\Framework\TestCase;

final class CircuitBreakerTest extends TestCase
{
    private float $now = 1_000.0;

    private function breaker(): CircuitBreaker
    {
        return new CircuitBreaker((new FakeRedisStore)->redis($this), fn (): float => $this->now);
    }

    public function test_pieciu_porazek_otwiera_na_30_sekund_potem_przepuszcza(): void
    {
        $breaker = $this->breaker();

        for ($i = 0; $i < 4; $i++) {
            $breaker->failure();
            self::assertTrue($breaker->allow(), "po {$i}+1 porażkach jeszcze zamknięty");
        }
        $breaker->failure();

        self::assertFalse($breaker->allow());
        self::assertTrue($breaker->isOpen());

        $this->now += 29.0;
        self::assertFalse($breaker->allow());

        $this->now += 1.0;
        self::assertTrue($breaker->allow());
        self::assertFalse($breaker->isOpen());
    }

    public function test_sukces_zeruje_licznik_kolejnych_porazek(): void
    {
        $breaker = $this->breaker();
        for ($i = 0; $i < 4; $i++) {
            $breaker->failure();
        }
        $breaker->success();
        $breaker->failure();

        self::assertTrue($breaker->allow());
    }

    public function test_porazka_po_polotwarciu_otwiera_ponownie(): void
    {
        $breaker = $this->breaker();
        for ($i = 0; $i < 5; $i++) {
            $breaker->failure();
        }
        $this->now += 31.0;
        self::assertTrue($breaker->allow());

        $breaker->failure();

        self::assertFalse($breaker->allow());
    }
}
