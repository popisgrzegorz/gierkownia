<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Core\Game\Actor;
use App\Core\Game\GameDefinition;
use App\Core\Game\GameInterface;
use App\Core\Room\Room;
use App\Core\Room\Team;

/**
 * Minimalna gra do testow rejestru - liczy sie wylacznie type().
 * Bez zaleznosci, zeby test rejestru nie potrzebowal Redisa ani FSM.
 */
final class BetaGame implements GameInterface
{
    public static function type(): string
    {
        return 'beta';
    }

    public function name(): string
    {
        return 'Gra Beta';
    }

    public function definition(): GameDefinition
    {
        return new GameDefinition(self::type(), 'idle', ['idle'], []);
    }

    public function handleAction(Room $room, string $action, array $payload, Actor $actor): array
    {
        return [];
    }

    public function hostView(Room $room): array
    {
        return [];
    }

    public function playerView(Room $room, Team $team): array
    {
        return [];
    }
}
