<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Core\Game\AbstractGame;
use App\Core\Game\Actor;
use App\Core\Game\GameDefinition;
use App\Core\Game\IllegalActionException;
use App\Core\Game\Transition;
use App\Core\Realtime\Event;
use App\Core\Realtime\Message;
use App\Core\Room\Room;
use App\Core\Room\Team;

/**
 * Atrapa modulu gry na potrzeby testow rdzenia.
 *
 * Jest tu takze po to, by udowodnic teze ze spec 7: gra dokladana do platformy
 * nie wymaga zadnej zmiany w rdzeniu. Ten plik lezy w testach, rdzen nic o nim
 * nie wie, a mimo to daje sie uruchomic przez GameEngine jak kazda inna gra.
 */
final class PingPongGame extends AbstractGame
{
    public static function type(): string
    {
        return 'pingpong';
    }

    public function name(): string
    {
        return 'Ping Pong';
    }

    public function definition(): GameDefinition
    {
        return new GameDefinition(
            type: self::type(),
            initialState: 'idle',
            states: ['idle', 'serving', 'rally', 'point'],
            transitions: [
                new Transition('serve', 'idle', 'serving'),
                new Transition('hit', 'serving', 'rally'),
                new Transition('score', 'rally', 'point'),
                // Z dwoch stanow do jednego - sprawdza, czy FSM to obsluguje.
                new Transition('reset', ['point', 'rally'], 'idle'),
            ],
        );
    }

    public function handleAction(Room $room, string $action, array $payload, Actor $actor): array
    {
        return match ($action) {
            'serve' => $this->serve($room, $actor),
            'hit' => $this->hit($room, $actor),
            'score' => $this->score($room, $actor),
            'reset' => $this->reset($room, $actor),
            'peek' => $this->peek($room, $actor),
            'noop' => [],
            default => throw new IllegalActionException(sprintf('Ping Pong nie zna akcji "%s".', $action)),
        };
    }

    public function hostView(Room $room): array
    {
        return ['state' => $this->state($room), 'secret' => $this->ctx($room)['_secret'] ?? null];
    }

    public function playerView(Room $room, Team $team): array
    {
        return ['state' => $this->state($room), 'teamName' => $team->name];
    }

    /** @return list<Message> */
    private function serve(Room $room, Actor $actor): array
    {
        $actor->requireHost();
        $this->transition($room, 'serve');
        $this->patchCtx($room, ['rallies' => 0, '_secret' => 'tego nikt nie moze zobaczyc']);

        return [Message::broadcast(new Event('serve'))];
    }

    /** @return list<Message> */
    private function hit(Room $room, Actor $actor): array
    {
        $team = $actor->requireTeam();
        $this->transition($room, 'hit');

        $rallies = $this->ctx($room)['rallies'] ?? 0;
        $this->patchCtx($room, ['rallies' => (\is_int($rallies) ? $rallies : 0) + 1]);

        return [
            Message::broadcast(new Event('hit', ['teamId' => $team->id])),
            Message::toTeam($team->id, new Event('your_turn')),
        ];
    }

    /** @return list<Message> */
    private function score(Room $room, Actor $actor): array
    {
        $actor->requireHost();
        $this->transition($room, 'score');

        return [Message::broadcast(new Event(Event::SCORE, ['total' => 1]))];
    }

    /** @return list<Message> */
    private function reset(Room $room, Actor $actor): array
    {
        $actor->requireHost();
        $this->transition($room, 'reset');

        return [];
    }

    /**
     * Akcja bez przejscia - sluzy do sprawdzenia, ze rdzen nie oglasza fazy na pusto.
     *
     * @return list<Message>
     */
    private function peek(Room $room, Actor $actor): array
    {
        $actor->requireHost();

        return [Message::toHost(new Event('peek', ['secret' => $this->ctx($room)['_secret'] ?? null]))];
    }
}
