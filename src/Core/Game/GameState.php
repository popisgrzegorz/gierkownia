<?php

declare(strict_types=1);

namespace App\Core\Game;

use App\Core\Redis\RedisReader;
use App\Core\Room\RoomKeys;
use Predis\ClientInterface;

/**
 * Stan rozgrywki w pokoju: marking FSM + kontekst modulu (spec 5.1).
 *
 * Kontekst to workowy JSON, bo kazda gra trzyma w nim co innego - aktualne
 * haslo, czyja tura, ktora karta. Rdzen go nie interpretuje, tylko przechowuje;
 * struktura nalezy wylacznie do modulu.
 */
final readonly class GameState
{
    public function __construct(
        private ClientInterface $redis,
        private RedisReader $read,
        private int $roomTtl,
    ) {
    }

    public function state(string $code): ?string
    {
        return $this->read->string(RoomKeys::gameState($code));
    }

    public function setState(string $code, string $state): void
    {
        $this->redis->setex(RoomKeys::gameState($code), $this->roomTtl, $state);
    }

    /** @return array<string, mixed> */
    public function ctx(string $code): array
    {
        $json = $this->read->string(RoomKeys::gameCtx($code));

        if (null === $json) {
            return [];
        }

        $ctx = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        if (!\is_array($ctx)) {
            return [];
        }

        $typed = [];
        foreach ($ctx as $key => $value) {
            if (\is_string($key)) {
                $typed[$key] = $value;
            }
        }

        return $typed;
    }

    /** @param array<string, mixed> $ctx */
    public function setCtx(string $code, array $ctx): void
    {
        $this->redis->setex(
            RoomKeys::gameCtx($code),
            $this->roomTtl,
            json_encode($ctx, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * Dokleja klucze do kontekstu, zostawiajac reszte. Wygodne dla modulow,
     * ktore zmieniaja jedno pole (np. tylko ture) i nie chca nadpisac calosci.
     *
     * @param array<string, mixed> $patch
     *
     * @return array<string, mixed> kontekst po zmianie
     */
    public function mergeCtx(string $code, array $patch): array
    {
        $ctx = [...$this->ctx($code), ...$patch];
        $this->setCtx($code, $ctx);

        return $ctx;
    }

    /** Czysci stan gry, zostawiajac pokoj i druzyny. */
    public function clear(string $code): void
    {
        $this->redis->del([RoomKeys::gameState($code), RoomKeys::gameCtx($code)]);
    }
}
