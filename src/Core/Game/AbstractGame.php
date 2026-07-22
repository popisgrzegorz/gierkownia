<?php

declare(strict_types=1);

namespace App\Core\Game;

use App\Core\Game\Fsm\GameFsm;
use App\Core\Room\Room;

/**
 * Wygodna baza dla modulow gier - opakowuje FSM i kontekst, zeby modul
 * zajmowal sie mechanika, a nie przekazywaniem definicji w kolko.
 *
 * Implementacja GameInterface bez dziedziczenia po tej klasie jest w pelni
 * poprawna; to skrot, nie wymog.
 */
abstract class AbstractGame implements GameInterface
{
    public function __construct(
        protected readonly GameFsm $fsm,
        protected readonly GameState $gameState,
    ) {
    }

    protected function state(Room $room): string
    {
        return $this->fsm->state($this->definition(), $room->code);
    }

    protected function can(Room $room, string $transition): bool
    {
        return $this->fsm->can($this->definition(), $room->code, $transition);
    }

    /** Wykonuje przejscie; rzuca IllegalActionException, gdy jest niedozwolone. */
    protected function transition(Room $room, string $transition): string
    {
        return $this->fsm->apply($this->definition(), $room->code, $transition);
    }

    /** @return array<string, mixed> */
    protected function ctx(Room $room): array
    {
        return $this->gameState->ctx($room->code);
    }

    /**
     * @param array<string, mixed> $patch
     *
     * @return array<string, mixed>
     */
    protected function patchCtx(Room $room, array $patch): array
    {
        return $this->gameState->mergeCtx($room->code, $patch);
    }
}
