<?php

declare(strict_types=1);

namespace App\Tests\Game\Kalambury;

use App\Content\PhraseDeck;
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
use App\Core\Room\RoomKeys;
use App\Core\Room\RoomLock;
use App\Core\Room\RoomManager;
use App\Core\Room\Team;
use App\Core\Score\Scoreboard;
use App\Core\Timer\GameTimer;
use App\Core\Turn\TurnManager;
use App\Entity\Category;
use App\Entity\Phrase;
use App\Game\Kalambury\KalamburyGame;
use App\Repository\CategoryRepository;
use App\Repository\PhraseRepository;
use App\Tests\DoctrineTestCase;
use App\Tests\RedisConnection;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

/**
 * Kalambury przez silnik.
 *
 * Sedno tej gry to jedno: haslo zna dokladnie jeden telefon. Telewizor
 * pokazuje sam ksztalt, a kolo ratunkowe odslania z niego pierwsza litere -
 * i ani znaku wiecej.
 */
final class KalamburyGameTest extends DoctrineTestCase
{
    use RedisConnection;

    private const TTL = 60;
    private const PHRASE = 'Kot w butach';

    private GameEngine $engine;
    private GameState $gameState;
    private RoomManager $rooms;
    private Scoreboard $scoreboard;
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

        $this->seedCategory();

        $this->scoreboard = new Scoreboard($this->redis, $reader, $this->rooms, $publisher, self::TTL);

        $kalambury = new KalamburyGame(
            $fsm,
            $this->gameState,
            new PhraseDeck(
                self::getContainer()->get(CategoryRepository::class),
                self::getContainer()->get(PhraseRepository::class),
            ),
            new TurnManager($this->redis, $reader, $publisher, self::TTL),
            $this->scoreboard,
            new GameTimer($this->redis, $reader, self::TTL),
            $this->rooms,
        );

        $this->engine = new GameEngine(new GameRegistry([$kalambury]), $this->gameState, $fsm, $this->rooms, $publisher, new RoomLock($this->redis));

        $this->room = $this->rooms->create();
        $this->kanapowcy = $this->rooms->join($this->room->code, 'Kanapowcy');
        $this->dywanowi = $this->rooms->join($this->room->code, 'Dywanowi');

        $this->engine->start($this->room, KalamburyGame::type());
        $this->host('pick_category', ['category' => 'testowa']);
        $this->host('next_card');
    }

    private function seedCategory(): void
    {
        $category = new Category('testowa', 'Kategoria testowa', Category::KIND_HASLA, ['kalambury']);
        $this->entityManager->persist($category);

        $this->entityManager->persist(new Phrase($category, self::PHRASE));
        $this->entityManager->flush();
    }

    /** Telewizor pokazuje, ile wyrazow i ile liter - i nic ponadto. */
    public function testTheBoardShowsTheShapeOfThePhrase(): void
    {
        self::assertSame('___ _ ______', $this->engine->hostView($this->room)['mask']);
    }

    /**
     * Haslo zna wylacznie aktor. Gdyby wyszlo rozgloszeniem, cala gra
     * konczylaby sie na zajrzeniu w narzedzia deweloperskie.
     */
    public function testOnlyTheActingTeamKnowsThePhrase(): void
    {
        self::assertSame(self::PHRASE, $this->engine->playerView($this->room, $this->kanapowcy)['word']);
        self::assertNull($this->engine->playerView($this->room, $this->dywanowi)['word']);
    }

    /** Kolo ratunkowe odslania pierwsza litere - reszta zostaje zakryta. */
    public function testTheLifelineRevealsTheFirstLetterOnly(): void
    {
        $this->host('start', ['seconds' => 60]);
        $this->host('hint');

        self::assertSame('K__ _ ______', $this->engine->hostView($this->room)['mask']);
        self::assertTrue($this->engine->hostView($this->room)['hintUsed']);
    }

    /**
     * Druga podpowiedz zamienilaby kalambury w zgadywanke z plansza, a caly
     * sens jest w pokazywaniu.
     */
    public function testTheLifelineWorksOncePerCard(): void
    {
        $this->host('hint');

        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessage('już użyte');

        $this->host('hint');
    }

    /**
     * Jednoliterowy wyraz to spojnik albo przyimek - odslanianie go oddaje
     * caly wyraz i nie mowi nic o hasle. Podpowiedz przeskakuje na pierwszy
     * wyraz, ktory ma wiecej niz jedna litere.
     */
    public function testTheLifelineSkipsOneLetterWords(): void
    {
        $this->host('skip');
        $this->seedCard('W lesie');
        $this->host('next_card');

        $this->host('hint');

        self::assertSame('_ L____', $this->engine->hostView($this->room)['mask']);
    }

    /** Nowa karta to nowa runda - kolo ratunkowe wraca. */
    public function testANewCardBringsTheLifelineBack(): void
    {
        $this->host('hint');
        $this->host('skip');

        $this->seedCard('Zamek');
        $this->host('next_card');

        self::assertFalse($this->engine->hostView($this->room)['hintUsed']);
        self::assertSame('_____', $this->engine->hostView($this->room)['mask']);
    }

    /** Bez wylosowanej karty nie ma czego podpowiadac. */
    public function testTheLifelineIsRefusedOutsideARound(): void
    {
        $this->host('skip');

        $this->expectException(IllegalActionException::class);

        $this->host('hint');
    }

    /**
     * Podpowiedz idzie rozgloszeniem - patrzy na nia caly pokoj - ale niesie
     * wylacznie maske, nigdy hasla.
     */
    public function testTheLifelineBroadcastsTheMaskAndNotThePhrase(): void
    {
        $this->published = [];
        $this->host('hint');

        $published = implode(' ', array_map(
            static fn (Update $update): string => $update->getData(),
            $this->published,
        ));

        self::assertStringContainsString('K__ _ ______', $published);
        self::assertStringNotContainsString(self::PHRASE, $published);
    }

    /** Zgadniete daje punkt zarowno zgadujacym, jak i pokazujacym. */
    public function testGuessedAwardsAPointToBothTeams(): void
    {
        $this->host('start', ['seconds' => 60]);
        $this->host('guessed', ['teamId' => $this->dywanowi->id]);

        self::assertSame(1, $this->scoreboard->get($this->room->code, $this->dywanowi->id));
        self::assertSame(1, $this->scoreboard->get($this->room->code, $this->kanapowcy->id));
    }

    /** Po uplywie czasu nikt nie zgadl - nikt nie dostaje punktu. */
    public function testTimeoutEndsTheRoundWithoutAnyScore(): void
    {
        $this->host('start', ['seconds' => 15]);
        $this->expireTimer();

        $this->host('timeout');

        self::assertSame(0, $this->scoreboard->get($this->room->code, $this->dywanowi->id));
        self::assertSame(0, $this->scoreboard->get($this->room->code, $this->kanapowcy->id));
    }

    /**
     * Host klika "Zgadnieto" w tej samej chwili, gdy zegar telewizora juz
     * wyslal timeout - zadanie dociera do serwera, gdy stan jest juz
     * 'timeout'. RoomLock nie dopuszcza do przekłamania danych, ale bez
     * tego przejscia punkt nie trafialby do nikogo, mimo ze ktos naprawde
     * zgadl. Host jest sedzia - jego klik ma wygrac ten wyscig.
     */
    public function testGuessedArrivingJustAfterTimeoutStillAwardsThePoint(): void
    {
        $this->host('start', ['seconds' => 15]);
        $this->expireTimer();

        $this->host('timeout');
        $this->host('guessed', ['teamId' => $this->dywanowi->id]);

        self::assertSame('guessed', $this->engine->hostView($this->room)['state']);
        self::assertSame(1, $this->scoreboard->get($this->room->code, $this->dywanowi->id));
        self::assertSame(1, $this->scoreboard->get($this->room->code, $this->kanapowcy->id));
    }

    /** Po zgadnieciu runda jest zamknieta - drugie zgadniecie to juz nielegalne przejscie. */
    public function testASecondGuessedAfterOneAlreadyResolvedIsRefused(): void
    {
        $this->host('start', ['seconds' => 60]);
        $this->host('guessed', ['teamId' => $this->dywanowi->id]);

        $this->expectException(IllegalActionException::class);

        $this->host('guessed', ['teamId' => $this->kanapowcy->id]);
    }

    private function expireTimer(): void
    {
        $this->redis->setex(RoomKeys::gameDeadline($this->room->code), self::TTL, (string) ((int) (microtime(true) * 1000) - 1000));
    }

    private function seedCard(string $phrase): void
    {
        $category = self::getContainer()->get(CategoryRepository::class)->findOneBySlug('testowa');
        self::assertInstanceOf(Category::class, $category);

        $this->entityManager->persist(new Phrase($category, $phrase));
        $this->entityManager->flush();
    }

    /** @param array<string, mixed> $payload */
    private function host(string $action, array $payload = []): void
    {
        $this->engine->handle($this->room, $action, $payload, Actor::host());
    }
}
