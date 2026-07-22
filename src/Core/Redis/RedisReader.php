<?php

declare(strict_types=1);

namespace App\Core\Redis;

use Predis\ClientInterface;

/**
 * Odczyty z Redisa sprowadzone do konkretnych typow.
 *
 * Klient Predis zwraca mixed - co jest uczciwe, bo po drugiej stronie moze byc
 * cokolwiek. Zamiast rzutowac tego w kilkunastu miejscach, przepuszczamy odczyty
 * przez jedno miejsce, ktore wie czego oczekujemy i odsiewa reszte.
 */
final readonly class RedisReader
{
    public function __construct(
        private ClientInterface $redis,
    ) {
    }

    /** @return array<string, string> */
    public function hash(string $key): array
    {
        $hash = [];

        foreach ($this->redis->hgetall($key) as $field => $value) {
            if (\is_string($field) && \is_string($value)) {
                $hash[$field] = $value;
            }
        }

        return $hash;
    }

    public function string(string $key): ?string
    {
        $value = $this->redis->get($key);

        return \is_string($value) ? $value : null;
    }

    public function int(string $key): int
    {
        $value = $this->redis->get($key);

        return \is_numeric($value) ? (int) $value : 0;
    }

    /** @return list<string> */
    public function keys(string $pattern): array
    {
        return array_values(array_filter($this->redis->keys($pattern), \is_string(...)));
    }

    /**
     * Wartosci wielu kluczy naraz, w kolejnosci zapytania. Brakujace lub
     * nieliczbowe wpisy daja 0 - dla tablo to naturalne "jeszcze nie punktowal".
     *
     * @param list<string> $keys
     *
     * @return list<int>
     */
    public function ints(array $keys): array
    {
        if ([] === $keys) {
            return [];
        }

        $values = $this->redis->mget($keys);
        $ints = [];

        foreach ($keys as $i => $key) {
            $value = $values[$i] ?? null;
            $ints[] = \is_numeric($value) ? (int) $value : 0;
        }

        return $ints;
    }
}
