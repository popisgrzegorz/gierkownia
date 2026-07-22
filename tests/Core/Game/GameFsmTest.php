<?php

declare(strict_types=1);

namespace App\Tests\Core\Game;

use App\Core\Game\Fsm\GameFsm;
use App\Core\Game\Fsm\RedisMarkingStore;
use App\Core\Game\GameDefinition;
use App\Core\Game\GameState;
use App\Core\Game\IllegalActionException;
use App\Core\Game\Transition;
use App\Core\Redis\RedisReader;
use App\Core\Room\RoomKeys;
use App\Tests\RedisTestCase;

final class GameFsmTest extends RedisTestCase
{
    private const ROOM = 'ABCD';
    private const TTL = 60;

    private GameFsm $fsm;
    private GameState $state;
    private GameDefinition $definition;

    protected function setUp(): void
    {
        parent::setUp();

        $this->state = new GameState($this->redis, new RedisReader($this->redis), self::TTL);
        $this->fsm = new GameFsm(new RedisMarkingStore($this->state));

        $this->definition = new GameDefinition(
            type: 'melodia',
            initialState: 'idle',
            states: ['idle', 'armed', 'buzzed', 'judging'],
            transitions: [
                new Transition('arm', 'idle', 'armed'),
                new Transition('buzz', 'armed', 'buzzed'),
                new Transition('judge', 'buzzed', 'judging'),
                new Transition('finish', ['judging', 'armed'], 'idle'),
            ],
        );
    }

    public function testFreshRoomStartsInTheInitialState(): void
    {
        self::assertSame('idle', $this->fsm->state($this->definition, self::ROOM));
    }

    public function testFullRoundWalksThroughTheStates(): void
    {
        self::assertSame('armed', $this->fsm->apply($this->definition, self::ROOM, 'arm'));
        self::assertSame('buzzed', $this->fsm->apply($this->definition, self::ROOM, 'buzz'));
        self::assertSame('judging', $this->fsm->apply($this->definition, self::ROOM, 'judge'));
        self::assertSame('idle', $this->fsm->apply($this->definition, self::ROOM, 'finish'));
    }

    public function testTransitionMayStartFromSeveralStates(): void
    {
        $this->fsm->apply($this->definition, self::ROOM, 'arm');

        // Host przerywa runde bez buzza - "finish" prowadzi tez z armed.
        self::assertSame('idle', $this->fsm->apply($this->definition, self::ROOM, 'finish'));
    }

    /**
     * Najczestszy przypadek w praktyce: telefon wysyla akcje na podstawie
     * ekranu sprzed sekundy. To ma byc odmowa, a nie awaria.
     */
    public function testTransitionFromTheWrongStateIsRefused(): void
    {
        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessageMatches('/niedozwolone w stanie "idle"/');

        $this->fsm->apply($this->definition, self::ROOM, 'buzz');
    }

    public function testUnknownTransitionIsRefused(): void
    {
        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessageMatches('/nie zna przejscia "teleportuj"/');

        $this->fsm->apply($this->definition, self::ROOM, 'teleportuj');
    }

    public function testRefusedTransitionLeavesTheStateUntouched(): void
    {
        $this->fsm->apply($this->definition, self::ROOM, 'arm');

        try {
            $this->fsm->apply($this->definition, self::ROOM, 'judge');
        } catch (IllegalActionException) {
            // oczekiwane
        }

        self::assertSame('armed', $this->fsm->state($this->definition, self::ROOM));
    }

    public function testCanReportsWhatIsPossibleRightNow(): void
    {
        self::assertTrue($this->fsm->can($this->definition, self::ROOM, 'arm'));
        self::assertFalse($this->fsm->can($this->definition, self::ROOM, 'buzz'));

        $this->fsm->apply($this->definition, self::ROOM, 'arm');

        self::assertSame(['buzz', 'finish'], $this->fsm->available($this->definition, self::ROOM));
    }

    /**
     * Sedno spec 7 i 15: stan nie moze siedziec w pamieci procesu. Backend pod
     * PHP-FPM obsluguje kolejne zadania w roznych procesach, wiec swiezy obiekt
     * FSM musi widziec dokladnie to samo, co poprzedni.
     */
    public function testStateSurvivesInRedisNotInProcessMemory(): void
    {
        $this->fsm->apply($this->definition, self::ROOM, 'arm');

        $freshProcess = new GameFsm(new RedisMarkingStore(
            new GameState($this->redis, new RedisReader($this->redis), self::TTL),
        ));

        self::assertSame('armed', $freshProcess->state($this->definition, self::ROOM));
        self::assertSame('armed', (string) $this->redis->get(RoomKeys::gameState(self::ROOM)));
    }

    public function testRoomsDoNotShareState(): void
    {
        $this->fsm->apply($this->definition, self::ROOM, 'arm');

        self::assertSame('idle', $this->fsm->state($this->definition, 'WXYZ'));
    }

    public function testStateKeyExpiresWithTheRoom(): void
    {
        $this->fsm->apply($this->definition, self::ROOM, 'arm');

        self::assertGreaterThan(0, (int) $this->redis->ttl(RoomKeys::gameState(self::ROOM)));
    }
}
