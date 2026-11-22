<?php

namespace App\Seller360;

/**
 * Połączenie phpredis dla projektora seller-360 (zbiór brudnych sprzedawców, liczniki metryk).
 */
final class RedisFactory
{
    public static function create(string $host, string $password): \Redis
    {
        $redis = new \Redis;
        $redis->connect($host, 6379, 2.0);
        if ($password !== '') {
            $redis->auth($password);
        }

        return $redis;
    }
}
