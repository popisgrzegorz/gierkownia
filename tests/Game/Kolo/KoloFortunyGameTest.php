<?php

declare(strict_types=1);

namespace App\Tests\Game\Kolo;

use App\Core\Game\Actor;
use App\Core\Game\Fsm\GameFsm;
use App\Core\Game\Fsm\RedisMarkingStore;
use App\Core\Game\GameEngine;
use App\Core\Game\GameRegistry;
use App\Core\Game\GameState;
use App\Core\Game\IllegalActionException;
use App\Core\Realtime\Publisher;
use App\Core\Redis\RedisReader;
use App\Core\Room\Room;
use App\Core\Room\RoomCodeGenerator;
use App\Core\Room\RoomManager;
use App\Core\Room\Team;
use App\Core\Score\Scoreboard;
use App\Core\Turn\TurnManager;
use App\Entity\Deck;
use App\Entity\PuzzleWord;
use App\Game\Kolo\KoloFortunyGame;
use App\Game\Kolo\KoloPuzzles;
use App\Game\Kolo\Wheel;
use App\Repository\DeckRepository;
use App\Repository\PuzzleWordRepository;
use App\Tests\DoctrineTestCase;
use App\Tests\RedisConnection;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

/**
 * Kolo fortuny przez silnik.
 *
 * Wynik kola jest losowy, wiec tam gdzie liczy sie konkretny sektor,
 * podmieniamy go w Redisie po zakreceniu. Inaczej testy bankruta przechodzilyby
 * raz na szesc uruchomien.
 */
final class KoloFortunyGameTest extends DoctrineTestCase
{
    use RedisConnection;

    private const TTL = 60;
    private const PHRASE = 'KASA KWIATOWA';

    private GameEngine $engine;
    private GameState $gameState;
    private Scoreboard $scoreboard;
    private TurnManager $turns;
    private RoomManager $rooms;
    private Room $room;
    private Team $kanapowcy;
    private Team $dywanowi;

    /** @var list<Update> */
    private array $published = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectRedis();

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

        $this->turns = new TurnManager($this->redis, $reader, $publisher, self::TTL);
        $this->scoreboard = new Scoreboard($this->redis, $reader, $this->rooms, $publisher, self::TTL);

        $this->seedDeck();

        $kolo = new KoloFortunyGame(
            $fsm,
            $this->gameState,
            $this->turns,
            $this->scoreboard,
            $this->rooms,
            new KoloPuzzles(
                self::getContainer()->get(DeckRepository::class),
                self::getContainer()->get(PuzzleWordRepository::class),
            ),
        );

        $this->engine = new GameEngine(new GameRegistry([$kolo]), $this->gameState, $fsm, $this->rooms, $publisher);

        $this->room = $this->rooms->create();
        $this->kanapowcy = $this->rooms->join($this->room->code, 'Kanapowcy');
        $this->dywanowi = $this->rooms->join($this->room->code, 'Dywanowi');

        $this->engine->start($this->room, 'kolo');
        $this->host('pick_deck', ['deck' => 'testowa']);
        $this->host('next_puzzle');
    }

    private function seedDeck(): void
    {
        $deck = new Deck('testowa', 'Talia testowa', 'kolo');
        $this->entityManager->persist($deck);

        $puzzle = new PuzzleWord($deck, self::PHRASE);
        $puzzle->setHint('zwiazek frazeologiczny');
        $this->entityManager->persist($puzzle);
        $this->entityManager->flush();
    }

    public function testNewPuzzleStartsHiddenWithTheFirstTeamOnTurn(): void
    {
        $view = $this->engine->playerView($this->room, $this->kanapowcy);

        self::assertSame('____ ________', $view['mask']);
        self::assertSame('zwiazek frazeologiczny', $view['hint']);
        self::assertTrue($view['myTurn']);
        self::assertFalse($this->engine->playerView($this->room, $this->dywanowi)['myTurn']);
    }

    /** Haslo jest tajne. Wyciek na telefon konczy gre, zanim sie zacznie. */
    public function testThePhraseNeverReachesThePlayers(): void
    {
        $view = $this->engine->playerView($this->room, $this->kanapowcy);

        self::assertArrayNotHasKey('phrase', $view);
        self::assertArrayNotHasKey('_phrase', $view);

        foreach ($this->published as $update) {
            self::assertStringNotContainsString(self::PHRASE, $update->getData());
        }

        // ...ale host musi je widziec, zeby prowadzic gre.
        self::assertSame(self::PHRASE, $this->engine->hostView($this->room)['phrase']);
    }

    public function testSpinResultComesFromTheServerNotTheScreen(): void
    {
        $mark = \count($this->published);

        $this->team($this->kanapowcy, 'spin');

        $payload = $this->findEventSince($mark, 'wheel_result');

        self::assertIsInt($payload['index']);
        self::assertContains($payload['value'], Wheel::sectors());
        self::assertSame('spinning', $this->engine->currentState($this->room));
    }

    public function testHitLettersFillTheBoardAndFeedTheBank(): void
    {
        $this->spinTo(500);

        $this->team($this->kanapowcy, 'letter', ['letter' => 'A']);

        $view = $this->engine->playerView($this->room, $this->kanapowcy);

        self::assertSame('_A_A ___A___A', $view['mask']);
        self::assertSame(2000, $view['myBank'], '4 litery A po 500 punktow.');
        self::assertTrue($view['myTurn'], 'Trafiona litera zostawia ture przy druzynie.');
        self::assertSame('next_team', $this->engine->currentState($this->room), 'Granica tury - ta sama druzyna kreci dalej.');
    }

    public function testMissedLetterPassesTheTurn(): void
    {
        $this->spinTo(500);

        $this->team($this->kanapowcy, 'letter', ['letter' => 'Z']);

        self::assertSame(0, $this->engine->playerView($this->room, $this->kanapowcy)['myBank']);
        self::assertTrue($this->engine->playerView($this->room, $this->dywanowi)['myTurn']);
        self::assertSame('next_team', $this->engine->currentState($this->room));
    }

    public function testTheSameLetterCannotBeTriedTwice(): void
    {
        $this->spinTo(500);
        $this->team($this->kanapowcy, 'letter', ['letter' => 'A']);
        $this->spinTo(300);

        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessageMatches('/byla juz sprawdzana/');

        $this->team($this->kanapowcy, 'letter', ['letter' => 'a']);
    }

    /** Bankrut ma miec cene - inaczej kręcenie byloby zawsze oplacalne. */
    public function testBankruptWipesTheBankAndEndsTheTurn(): void
    {
        $this->spinTo(500);
        $this->team($this->kanapowcy, 'letter', ['letter' => 'A']);
        self::assertSame(2000, $this->engine->playerView($this->room, $this->kanapowcy)['myBank']);

        $this->spinTo(Wheel::BANKRUT);

        self::assertSame(0, $this->engine->playerView($this->room, $this->kanapowcy)['myBank']);
        self::assertTrue($this->engine->playerView($this->room, $this->dywanowi)['myTurn']);
    }

    public function testStopEndsTheTurnButLeavesTheBankAlone(): void
    {
        $this->spinTo(500);
        $this->team($this->kanapowcy, 'letter', ['letter' => 'A']);

        $this->spinTo(Wheel::STOP);

        self::assertSame(2000, $this->engine->playerView($this->room, $this->kanapowcy)['myBank']);
        self::assertTrue($this->engine->playerView($this->room, $this->dywanowi)['myTurn']);
    }

    public function testSolvingPaysOutTheBankWithABonus(): void
    {
        $this->spinTo(500);
        $this->team($this->kanapowcy, 'letter', ['letter' => 'A']);

        $this->team($this->kanapowcy, 'solve', ['phrase' => 'kasa kwiatowa']);

        self::assertSame('solved', $this->engine->currentState($this->room));
        self::assertSame(2500, $this->scoreboard->get($this->room->code, $this->kanapowcy->id), 'bank 2000 + bonus 500');
        self::assertSame(self::PHRASE, $this->engine->playerView($this->room, $this->kanapowcy)['mask']);
    }

    public function testWrongAnswerCostsTheTurnButNotTheBank(): void
    {
        $this->spinTo(500);
        $this->team($this->kanapowcy, 'letter', ['letter' => 'A']);

        $this->team($this->kanapowcy, 'solve', ['phrase' => 'kasa pancerna']);

        self::assertSame(0, $this->scoreboard->get($this->room->code, $this->kanapowcy->id));
        self::assertSame(2000, $this->engine->playerView($this->room, $this->kanapowcy)['myBank']);
        self::assertTrue($this->engine->playerView($this->room, $this->dywanowi)['myTurn']);
    }

    /** Ostatnia litera konczy haslo sama - nikt nie musi go juz "zgadywac". */
    public function testRevealingTheLastLetterSolvesThePuzzle(): void
    {
        foreach (['A', 'K', 'S', 'W', 'I', 'T', 'O'] as $letter) {
            if ('solved' === $this->engine->currentState($this->room)) {
                break;
            }

            $this->spinTo(200);
            $this->team($this->currentTeam(), 'letter', ['letter' => $letter]);
        }

        self::assertSame('solved', $this->engine->currentState($this->room));
        self::assertSame(self::PHRASE, $this->engine->playerView($this->room, $this->kanapowcy)['mask']);
    }

    public function testATeamOutOfTurnCannotPlay(): void
    {
        $this->spinTo(500);

        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessageMatches('/nie jest wasza kolej/');

        $this->team($this->dywanowi, 'letter', ['letter' => 'A']);
    }

    public function testGarbageInsteadOfALetterIsRefused(): void
    {
        $this->spinTo(500);

        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessageMatches('/To nie jest litera/');

        $this->team($this->kanapowcy, 'letter', ['letter' => 'AB']);
    }

    public function testHostCanHandTheTurnOverWhenTheTableGetsStuck(): void
    {
        $this->spinTo(500);

        $this->host('skip_turn');

        self::assertTrue($this->engine->playerView($this->room, $this->dywanowi)['myTurn']);
    }

    public function testNextPuzzleGoesToTheTeamThatSolvedTheLastOne(): void
    {
        $this->spinTo(500);
        $this->team($this->kanapowcy, 'letter', ['letter' => 'Z']); // pudlo, tura do Dywanowych
        $this->spinTo(500);
        $this->team($this->dywanowi, 'solve', ['phrase' => self::PHRASE]);

        $this->seedSecondPuzzle();
        $this->host('next_puzzle');

        self::assertTrue($this->engine->playerView($this->room, $this->dywanowi)['myTurn']);
    }

    private function seedSecondPuzzle(): void
    {
        $deck = self::getContainer()->get(DeckRepository::class)->findOneBySlug('testowa');
        self::assertNotNull($deck);

        $this->entityManager->persist(new PuzzleWord($deck, 'DRUGIE HASLO'));
        $this->entityManager->flush();
    }

    /**
     * Kreci kolem i podmienia wynik na zadany sektor, po czym melduje zatrzymanie.
     * Bez podmiany testy konkretnych sektorow byly by loteria.
     */
    private function spinTo(int|string $value): void
    {
        $this->team($this->currentTeam(), 'spin');

        $index = array_search($value, Wheel::sectors(), true);
        self::assertIsInt($index, 'Nie ma takiego sektora na kole.');

        $this->gameState->mergeCtx($this->room->code, ['wheel' => ['index' => $index, 'value' => $value]]);

        $this->host('land');
    }

    private function currentTeam(): Team
    {
        $id = $this->turns->current($this->room->code);

        return $id === $this->kanapowcy->id ? $this->kanapowcy : $this->dywanowi;
    }

    /** @param array<string, mixed> $payload */
    private function host(string $action, array $payload = []): void
    {
        $this->engine->handle($this->room, $action, $payload, Actor::host());
    }

    /** @param array<string, mixed> $payload */
    private function team(Team $team, string $action, array $payload = []): void
    {
        $this->engine->handle($this->room, $action, $payload, Actor::team($team));
    }

    /**
     * Znajduje event danego typu wsrod opublikowanych po znaczniku.
     * Rdzen doklada wlasny event zmiany fazy, wiec nie jest jedyny.
     *
     * @return array<string, mixed>
     */
    private function findEventSince(int $mark, string $type): array
    {
        foreach (\array_slice($this->published, $mark) as $update) {
            $payload = $this->decode($update->getData());

            if ($type === ($payload['type'] ?? null)) {
                return $payload;
            }
        }

        self::fail(sprintf('Nie opublikowano eventu "%s".', $type));
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        $payload = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
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
