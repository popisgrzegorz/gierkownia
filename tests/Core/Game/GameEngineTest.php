<?php

declare(strict_types=1);

namespace App\Tests\Core\Game;

use App\Core\Game\Actor;
use App\Core\Game\Fsm\GameFsm;
use App\Core\Game\Fsm\RedisMarkingStore;
use App\Core\Game\GameEngine;
use App\Core\Game\GameRegistry;
use App\Core\Game\GameState;
use App\Core\Game\IllegalActionException;
use App\Core\Realtime\Event;
use App\Core\Realtime\Publisher;
use App\Core\Redis\RedisReader;
use App\Core\Room\Room;
use App\Core\Room\RoomCodeGenerator;
use App\Core\Room\RoomLock;
use App\Core\Room\RoomManager;
use App\Core\Room\Team;
use App\Tests\Fixtures\PingPongGame;
use App\Tests\RedisTestCase;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

final class GameEngineTest extends RedisTestCase
{
    private const TTL = 60;

    private GameEngine $engine;
    private GameState $gameState;
    private RoomManager $rooms;
    private Room $room;
    private Team $team;

    /** @var list<Update> */
    private array $published = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->published = [];

        $reader = new RedisReader($this->redis);
        $this->gameState = new GameState($this->redis, $reader, self::TTL);
        $fsm = new GameFsm(new RedisMarkingStore($this->gameState));
        $this->rooms = new RoomManager($this->redis, $reader, new RoomCodeGenerator(), self::TTL);

        $publisher = new Publisher(new MockHub(
            url: 'http://localhost/.well-known/mercure',
            jwtProvider: new StaticTokenProvider('test'),
            publisher: function (Update $update): string {
                $this->published[] = $update;

                return 'test-id';
            },
        ));

        $this->engine = new GameEngine(
            new GameRegistry([new PingPongGame($fsm, $this->gameState)]),
            $this->gameState,
            $fsm,
            $this->rooms,
            $publisher,
            new RoomLock($this->redis),
        );

        $this->room = $this->rooms->create();
        $this->team = $this->rooms->join($this->room->code, 'Kanapowcy');
    }

    /**
     * Teza ze spec 7: gra, o ktorej rdzen nie wie nic, daje sie uruchomic
     * bez zmiany w rdzeniu. PingPongGame lezy w katalogu testow.
     */
    public function testStartsAGameTheCoreKnowsNothingAbout(): void
    {
        $game = $this->engine->start($this->room, 'pingpong');

        self::assertSame('pingpong', $game::type());
        self::assertSame('idle', $this->gameState->state($this->room->code));
        self::assertSame('idle', $this->rooms->getOrFail($this->room->code)->phase);
        self::assertSame('pingpong', $this->rooms->getOrFail($this->room->code)->gameType);
    }

    public function testStartingAnnouncesTheGameToEveryScreen(): void
    {
        $this->engine->start($this->room, 'pingpong');

        $payload = $this->lastPayload();

        self::assertSame('phase', $payload['type']);
        self::assertSame('pingpong', $payload['gameType']);
        self::assertSame('idle', $payload['state']);
    }

    /** Resztki po poprzedniej grze wyciekly by do modulu, ktory sie ich nie spodziewa. */
    public function testStartingWipesTheContextOfThePreviousGame(): void
    {
        $this->gameState->setCtx($this->room->code, ['phrase' => 'ZIMNY LEJEK']);

        $this->engine->start($this->room, 'pingpong');

        self::assertSame([], $this->gameState->ctx($this->room->code));
    }

    /**
     * state->clear() kasowal tylko game:state i game:ctx - reszta kluczy gry
     * (round, armed, winner:N, turn:*, deadline) zostawala z poprzedniej gry
     * i "wyciekala" do nastepnej w tym samym pokoju. Zegar to najbardziej
     * widoczny przypadek: Kalambury, Panstwa-Miasta i Dutch trzymaja swoj
     * termin pod tym samym kluczem, wiec nowa gra dostawalaby deadline,
     * ktorego nikt nie uruchomil.
     */
    public function testStartingClearsLeftoverKeysFromThePreviousGameLikeATimerDeadline(): void
    {
        $this->redis->setex(\App\Core\Room\RoomKeys::gameDeadline($this->room->code), self::TTL, '99999999999999');
        $this->redis->setex(\App\Core\Room\RoomKeys::gameRound($this->room->code), self::TTL, '7');

        $this->engine->start($this->room, 'pingpong');

        self::assertNull($this->redis->get(\App\Core\Room\RoomKeys::gameDeadline($this->room->code)));
        self::assertNull($this->redis->get(\App\Core\Room\RoomKeys::gameRound($this->room->code)));
    }

    /** Ten sam wyciek przy powrocie do lobby ("Zmień grę"), nie tylko przy starcie. */
    public function testStoppingAlsoClearsLeftoverGameKeys(): void
    {
        $this->engine->start($this->room, 'pingpong');
        $this->redis->setex(\App\Core\Room\RoomKeys::gameDeadline($this->room->code), self::TTL, '99999999999999');

        $this->engine->stop($this->room);

        self::assertNull($this->redis->get(\App\Core\Room\RoomKeys::gameDeadline($this->room->code)));
    }

    public function testActionIsDelegatedToTheModuleAndItsMessagesGoOut(): void
    {
        $this->engine->start($this->room, 'pingpong');
        $mark = \count($this->published);

        $result = $this->engine->handle($this->room, 'serve', [], Actor::host());

        self::assertSame('serving', $result['state']);
        self::assertSame(0, $result['ctx']['rallies'] ?? null);
        self::assertSame('serve', $this->payloadAt($mark)['type']);
    }

    /**
     * Zmiane fazy oglasza rdzen, nie modul - inaczej wystarczyloby, ze jeden
     * modul o tym zapomni, i ekrany zostana na poprzednim widoku.
     */
    public function testCoreAnnouncesThePhaseChangeItself(): void
    {
        $this->engine->start($this->room, 'pingpong');
        $mark = \count($this->published);

        $this->engine->handle($this->room, 'serve', [], Actor::host());

        $last = $this->lastPayload();
        self::assertSame('phase', $last['type']);
        self::assertSame('serving', $last['state']);
    }

    public function testNoPhaseEventWhenTheStateDidNotMove(): void
    {
        $this->engine->start($this->room, 'pingpong');
        $this->engine->handle($this->room, 'serve', [], Actor::host());
        $mark = \count($this->published);

        $this->engine->handle($this->room, 'peek', [], Actor::host());

        foreach (\array_slice($this->published, $mark) as $update) {
            self::assertStringNotContainsString('"type":"phase"', $update->getData());
        }
    }

    /**
     * Klucze z podkreslnikiem sa prywatne dla modulu. Broadcast leci na kazdy
     * telefon w pokoju, wiec doklejenie calego kontekstu zdradziloby rozwiazanie.
     */
    public function testPrivateContextKeysNeverLeaveInTheBroadcast(): void
    {
        $this->engine->start($this->room, 'pingpong');
        $this->engine->handle($this->room, 'serve', [], Actor::host());

        foreach ($this->published as $update) {
            self::assertStringNotContainsString('tego nikt nie moze zobaczyc', $update->getData());
        }

        // ...ale modul ma do nich dostep u siebie, np. na ekranie hosta.
        self::assertSame('tego nikt nie moze zobaczyc', $this->engine->hostView($this->room)['secret']);
    }

    /**
     * Odpowiedz HTTP na akcje dostaje takze telefon gracza - nie moze wiec
     * niesc kluczy prywatnych modulu (w melodii: id biezacego utworu).
     */
    public function testResponseToAnActionCarriesOnlyThePublicContext(): void
    {
        $this->engine->start($this->room, 'pingpong');

        $result = $this->engine->handle($this->room, 'serve', [], Actor::host());

        self::assertArrayHasKey('rallies', $result['ctx']);
        self::assertArrayNotHasKey('_secret', $result['ctx']);
    }

    public function testModuleCanSendAPrivateMessageToASingleTeam(): void
    {
        $this->engine->start($this->room, 'pingpong');
        $this->engine->handle($this->room, 'serve', [], Actor::host());
        $mark = \count($this->published);

        $this->engine->handle($this->room, 'hit', [], Actor::team($this->team));

        $private = array_values(array_filter(
            \array_slice($this->published, $mark),
            static fn (Update $u): bool => $u->isPrivate(),
        ));

        self::assertCount(1, $private);
        self::assertSame(['room/'.$this->room->code.'/team/'.$this->team->id], $private[0]->getTopics());
    }

    public function testActionForbiddenForThisActorIsRefused(): void
    {
        $this->engine->start($this->room, 'pingpong');

        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessageMatches('/tylko dla hosta/');

        $this->engine->handle($this->room, 'serve', [], Actor::team($this->team));
    }

    public function testActionInTheWrongStateIsRefused(): void
    {
        $this->engine->start($this->room, 'pingpong');

        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessageMatches('/niedozwolone w stanie "idle"/');

        $this->engine->handle($this->room, 'hit', [], Actor::team($this->team));
    }

    public function testUnknownActionIsRefused(): void
    {
        $this->engine->start($this->room, 'pingpong');

        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessageMatches('/nie zna akcji "fikolek"/');

        $this->engine->handle($this->room, 'fikolek', [], Actor::host());
    }

    public function testRoomWithoutAGameRefusesActions(): void
    {
        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessageMatches('/nie wybrano jeszcze gry/');

        $this->engine->handle($this->room, 'serve', [], Actor::host());
    }

    /**
     * Po zatrzymaniu pokoj nie moze twierdzic, ze gra trwa: ekran hosta
     * nie pokazalby wyboru gry, a telefony zostalyby w starym sterowniku.
     * To jest fundament grania w kilka gier na jednym kodzie pokoju.
     */
    public function testStoppingForgetsTheGameSoAnotherOneCanStart(): void
    {
        $this->engine->start($this->room, PingPongGame::type());
        $this->engine->stop($this->room);

        self::assertNull($this->rooms->getOrFail($this->room->code)->gameType);
        self::assertFalse($this->engine->hasGame($this->room));

        // Nowa gra rusza bez resetu pokoju - druzyny i punkty zostaja.
        $this->engine->start($this->room, PingPongGame::type());
        self::assertTrue($this->engine->hasGame($this->room));
        self::assertSame('idle', $this->engine->currentState($this->room));
    }

    public function testStoppingReturnsToLobbyButLeavesTeamsAlone(): void
    {
        $this->engine->start($this->room, 'pingpong');
        $this->engine->handle($this->room, 'serve', [], Actor::host());

        $this->engine->stop($this->room);

        self::assertNull($this->gameState->state($this->room->code));
        self::assertSame('lobby', $this->rooms->getOrFail($this->room->code)->phase);
        self::assertCount(1, $this->rooms->teams($this->room->code));
    }

    /** @return array<string, mixed> */
    private function lastPayload(): array
    {
        return $this->payloadAt(\count($this->published) - 1);
    }

    /** @return array<string, mixed> */
    private function payloadAt(int $index): array
    {
        self::assertArrayHasKey($index, $this->published, 'Nie opublikowano tylu eventow.');

        $payload = json_decode($this->published[$index]->getData(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        $typed = [];
        foreach ($payload as $key => $value) {
            if (\is_string($key)) {
                $typed[$key] = $value;
            }
        }

        return $typed;
    }
}
