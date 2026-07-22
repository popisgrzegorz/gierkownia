<?php

declare(strict_types=1);

namespace App\Core\Game;

/**
 * Odnajduje modul gry po jej typie.
 *
 * Moduly wpinaja sie same: kazda usluga implementujaca GameInterface dostaje
 * tag app.game (config/services.yaml) i trafia tutaj. Dolozenie gry nie wymaga
 * zmiany w tym pliku ani nigdzie indziej w rdzeniu.
 */
final class GameRegistry
{
    /** @var array<string, GameInterface>|null */
    private ?array $byType = null;

    /** @param iterable<GameInterface> $games */
    public function __construct(
        private readonly iterable $games,
    ) {
    }

    public function get(string $type): GameInterface
    {
        return $this->all()[$type] ?? throw new UnknownGameException($type, $this->types());
    }

    public function has(string $type): bool
    {
        return isset($this->all()[$type]);
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_keys($this->all());
    }

    /** @return array<string, GameInterface> */
    public function all(): array
    {
        if (null !== $this->byType) {
            return $this->byType;
        }

        $this->byType = [];

        foreach ($this->games as $game) {
            $type = $game::type();

            // Dwie gry pod jednym typem znaczy, ze rdzen ladowalby losowa z nich.
            if (isset($this->byType[$type])) {
                throw new \LogicException(sprintf(
                    'Typ gry "%s" jest zajety przez %s, a rejestruje sie tez %s.',
                    $type,
                    $this->byType[$type]::class,
                    $game::class,
                ));
            }

            $this->byType[$type] = $game;
        }

        return $this->byType;
    }
}
