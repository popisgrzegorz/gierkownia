<?php

declare(strict_types=1);

namespace App\Tests;

use Predis\Client;
use Predis\ClientInterface;

/**
 * Polaczenie z testowa baza Redisa (numer 15 z .env.test).
 *
 * Wydzielone do cechy, bo czesc testow potrzebuje samego Redisa, a czesc -
 * Redisa i Postgresa naraz, a dziedziczyc mozna tylko raz.
 */
trait RedisConnection
{
    protected ClientInterface $redis;

    protected function connectRedis(): void
    {
        $url = $_ENV['REDIS_URL'] ?? $_SERVER['REDIS_URL'] ?? null;

        if (!\is_string($url)) {
            self::markTestSkipped('REDIS_URL nie jest ustawione.');
        }

        $this->redis = new Client($url);

        try {
            $this->redis->ping();
        } catch (\Throwable $e) {
            self::markTestSkipped(sprintf('Redis niedostepny pod %s: %s', $url, $e->getMessage()));
        }

        $this->redis->flushdb();
    }
}
