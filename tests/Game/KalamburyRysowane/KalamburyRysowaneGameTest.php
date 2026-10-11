<?php

declare(strict_types=1);

namespace App\Tests\Game\KalamburyRysowane;

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
use App\Game\KalamburyRysowane\KalamburyRysowaneGame;
use App\Repository\CategoryRepository;
use App\Repository\PhraseRepository;
use App\Tests\DoctrineTestCase;
use App\Tests\RedisConnection;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

/**
 * Kalambury rysowane przez silnik.
 *
 * Dwa sedna tej gry: rysujący sam wybiera, ile punktów jest do zdobycia
 * (trzy oferty, po jednej z każdego poziomu trudności), a wybrana pula
 * topnieje z czasem - `pointsFor()` ma testy osobno jako czysta funkcja
 * i osobno przez pełny silnik, żeby złapać zarówno błąd wzoru, jak i błąd
 * przekazania właściwych liczb (basePoints, seconds, remaining) do niego.
 */
final class KalamburyRysowaneGameTest extends DoctrineTestCase
{
    use RedisConnection;

    private const TTL = 60;
    private const EASY_A = 'Kot w butach';
    private const EASY_B = 'Czerwony Kapturek';
    private const MEDIUM = 'Skrzypek na dachu';
    private const HARD = 'Dziewięćdziesiąt dziewięć balonów';

    private GameEngine $engine;
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
        $gameState = new GameState($this->redis, $reader, self::TTL);
        $fsm = new GameFsm(new RedisMarkingStore($gameState));
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

        $game = new KalamburyRysowaneGame(
            $fsm,
            $gameState,
            new PhraseDeck(
                self::getContainer()->get(CategoryRepository::class),
                self::getContainer()->get(PhraseRepository::class),
            ),
            new TurnManager($this->redis, $reader, $publisher, self::TTL),
            $this->scoreboard,
            new GameTimer($this->redis, $reader, self::TTL),
            $this->rooms,
        );

        $this->engine = new GameEngine(new GameRegistry([$game]), $gameState, $fsm, $this->rooms, $publisher, new RoomLock($this->redis));

        $this->room = $this->rooms->create();
        $this->kanapowcy = $this->rooms->join($this->room->code, 'Kanapowcy');
        $this->dywanowi = $this->rooms->join($this->room->code, 'Dywanowi');

        $this->engine->start($this->room, KalamburyRysowaneGame::type());
        $this->host('pick_category', ['category' => 'testowa', 'seconds' => 60]);
        $this->host('deal');
    }

    private function seedCategory(): void
    {
        // Dwie pozycje "latwy", po jednej "sredni"/"trudny" - dzieki temu jeden
        // test moze wyczerpac trudniejsze poziomy i sprawdzic, ze oferta
        // spada do samego "latwy" zamiast sie wywalic.
        $category = new Category('testowa', 'Kategoria testowa', Category::KIND_HASLA, ['kalambury-rysowane']);
        $this->entityManager->persist($category);

        $easyA = new Phrase($category, self::EASY_A);
        $easyA->setDifficulty('latwy');
        $easyB = new Phrase($category, self::EASY_B);
        $easyB->setDifficulty('latwy');
        $medium = new Phrase($category, self::MEDIUM);
        $medium->setDifficulty('sredni');
        $hard = new Phrase($category, self::HARD);
        $hard->setDifficulty('trudny');

        $this->entityManager->persist($easyA);
        $this->entityManager->persist($easyB);
        $this->entityManager->persist($medium);
        $this->entityManager->persist($hard);
        $this->entityManager->flush();
    }

    public function testDealOffersOneWordPerDifficultyLevel(): void
    {
        self::assertContains($this->phraseFor('latwy'), [self::EASY_A, self::EASY_B]);
        self::assertSame(self::MEDIUM, $this->phraseFor('sredni'));
        self::assertSame(self::HARD, $this->phraseFor('trudny'));
    }

    /** Skip odrzuca oferte, a odrzucone hasla NIE wracaja w kolejnej ofercie - tak samo jak w zwyklych Kalamburach. */
    public function testSkipDropsTheOfferedWordsAndTheyNeverComeBackAgain(): void
    {
        $this->host('skip');
        self::assertSame('idle', $this->engine->hostView($this->room)['state']);

        $this->host('deal');

        // "sredni" i "trudny" mialy tylko po jednej pozycji, ktora padla juz
        // w pierwszej ofercie - druga oferta ich po prostu nie zawiera.
        self::assertFalse($this->hasChoiceLevel('sredni'));
        self::assertFalse($this->hasChoiceLevel('trudny'));
        self::assertContains($this->phraseFor('latwy'), [self::EASY_A, self::EASY_B]);
    }

    /** Gdy WSZYSTKIE poziomy sa wyczerpane, deal() konczy sie czytelnym bledem, nie pusta oferta. */
    public function testDealFailsWhenTheWholeCategoryIsExhausted(): void
    {
        $this->host('skip');
        $this->host('deal'); // zuzywa druga (i ostatnia) pozycje "latwy"
        $this->host('skip');

        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessage('się skończyły');

        $this->host('deal');
    }

    public function testChooseStartsTheRoundWithThePickedDifficultyAndItsPoints(): void
    {
        $this->host('choose', ['level' => 'trudny']);
        $view = $this->engine->hostView($this->room);

        self::assertSame('drawing', $view['state']);
        self::assertSame(self::HARD, $view['word']);
        self::assertSame('trudny', $view['difficulty']);
        self::assertSame(8, $view['basePoints']);
    }

    public function testChooseRejectsALevelThatWasNotOffered(): void
    {
        $this->expectException(IllegalActionException::class);

        $this->host('choose', ['level' => 'nieznany']);
    }

    /** Prywatne oferty i haslo nie maja prawa wyciec na telefon graczy. */
    public function testChoicesAndWordNeverLeakToThePlayerView(): void
    {
        $this->host('choose', ['level' => 'sredni']);
        $view = $this->engine->playerView($this->room, $this->kanapowcy);

        self::assertArrayNotHasKey('word', $view);
        self::assertArrayNotHasKey('choices', $view);
    }

    public function testPlayerViewReportsWhichTeamIsCurrentlyDrawing(): void
    {
        self::assertTrue($this->engine->playerView($this->room, $this->kanapowcy)['isMyTeamDrawing']);
        self::assertFalse($this->engine->playerView($this->room, $this->dywanowi)['isMyTeamDrawing']);
    }

    public function testGuessedAwardsFullPointsWhenStillWithinTheGraceWindow(): void
    {
        $this->host('choose', ['level' => 'trudny']); // basePoints = 8, seconds = 60
        $this->setRemaining(58_000); // 2s uplynelo - w oknie 10s pelnej puli

        $this->host('guessed', ['teamId' => $this->dywanowi->id]);

        self::assertSame(8, $this->scoreboard->get($this->room->code, $this->dywanowi->id));
        self::assertSame(8, $this->scoreboard->get($this->room->code, $this->kanapowcy->id));
    }

    public function testGuessedAwardsFewerPointsTheLaterItComes(): void
    {
        $this->host('choose', ['level' => 'trudny']); // basePoints = 8, seconds = 60, decayWindow = 50
        $this->setRemaining(20_000); // fraction = 20/50 = 0.4 -> round(8 * 0.4) = 3

        $this->host('guessed', ['teamId' => $this->dywanowi->id]);

        self::assertSame(3, $this->scoreboard->get($this->room->code, $this->dywanowi->id));
    }

    public function testGuessedNeverAwardsLessThanOnePoint(): void
    {
        $this->host('choose', ['level' => 'latwy']); // basePoints = 3
        $this->setRemaining(0);

        $this->host('guessed', ['teamId' => $this->dywanowi->id]);

        self::assertSame(1, $this->scoreboard->get($this->room->code, $this->dywanowi->id));
    }

    /** Rysujaca druzyna dostaje te sama pule co zgadujaca - inaczej oplacaloby sie rysowac slabo. */
    public function testGuessedAwardsTheSamePointsToTheDrawingTeam(): void
    {
        $this->host('choose', ['level' => 'sredni']); // basePoints = 5
        $this->setRemaining(58_000);

        $this->host('guessed', ['teamId' => $this->dywanowi->id]);

        self::assertSame(5, $this->scoreboard->get($this->room->code, $this->kanapowcy->id));
    }

    public function testTimeoutRequiresAnActuallyExpiredTimer(): void
    {
        $this->host('choose', ['level' => 'latwy']);

        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessage('nie minął');

        $this->host('timeout');
    }

    public function testTimeoutEndsTheRoundWithoutAnyScore(): void
    {
        $this->host('choose', ['level' => 'latwy']);
        $this->expireTimer();

        $this->host('timeout');

        self::assertSame(0, $this->scoreboard->get($this->room->code, $this->dywanowi->id));
        self::assertSame(0, $this->scoreboard->get($this->room->code, $this->kanapowcy->id));
    }

    /**
     * Host klika "zgadnieto" tuz po tym, jak zegar telewizora juz wyslal
     * timeout - to samo przejscie co w zwyklych Kalamburach (guessed
     * przyjmuje 'timeout' jako zrodlo), punkt ma isc do zgadujacego.
     */
    public function testGuessedArrivingJustAfterTimeoutStillAwardsThePoint(): void
    {
        $this->host('choose', ['level' => 'latwy']);
        $this->expireTimer();
        $this->host('timeout');

        $this->host('guessed', ['teamId' => $this->dywanowi->id]);

        self::assertSame('guessed', $this->engine->hostView($this->room)['state']);
        self::assertSame(1, $this->scoreboard->get($this->room->code, $this->dywanowi->id));
    }

    public function testNextRoundAdvancesTheTurnAndClearsTheWord(): void
    {
        $this->host('choose', ['level' => 'latwy']);
        $this->host('guessed', ['teamId' => $this->dywanowi->id]);

        $this->host('next_round');
        $view = $this->engine->hostView($this->room);

        self::assertSame('idle', $view['state']);
        self::assertSame($this->dywanowi->id, $view['turn']);
        self::assertNull($view['word']);
    }

    public function testPickCategoryRejectsSecondsOutsideTheAllowedRange(): void
    {
        $this->expectException(IllegalActionException::class);

        $this->host('pick_category', ['category' => 'testowa', 'seconds' => 5]);
    }

    public function testDealRequiresACategoryFirst(): void
    {
        // Nowy pokoj bez wyboru kategorii - ten sam silnik, swiezy stan.
        $room = $this->rooms->create();
        $this->rooms->join($room->code, 'Solo');
        $this->rooms->join($room->code, 'Solo 2');
        $this->engine->start($room, KalamburyRysowaneGame::type());

        $this->expectException(IllegalActionException::class);

        $this->engine->handle($room, 'deal', [], Actor::host());
    }

    /** Czysta funkcja - wygodniej zlapac blad wzoru bez calego silnika dookola. */
    public function testPointsForIsFullWithinTheGraceWindow(): void
    {
        self::assertSame(8, KalamburyRysowaneGame::pointsFor(8, 90, 85));
    }

    public function testPointsForDecaysLinearlyAfterTheGraceWindow(): void
    {
        self::assertSame(4, KalamburyRysowaneGame::pointsFor(8, 90, 40));
    }

    public function testPointsForNeverDropsBelowOne(): void
    {
        self::assertSame(1, KalamburyRysowaneGame::pointsFor(8, 90, 0));
    }

    public function testPointsForStaysFullWhenTheRoundIsNotLongerThanTheGraceWindow(): void
    {
        self::assertSame(3, KalamburyRysowaneGame::pointsFor(3, 10, 1));
    }

    /**
     * choices_ready idzie ROZGŁOSZENIEM (Message::broadcast) do całego pokoju -
     * gdyby niosło treść ofert, każdy telefon zgadujących podejrzałby hasła
     * w narzędziach deweloperskich, zanim rysujący zdąży wybrać jedno z nich.
     */
    public function testChoicesReadyBroadcastNeverLeaksThePhraseText(): void
    {
        $this->published = [];
        $this->host('skip');
        $this->host('deal');

        $broadcast = implode(' ', array_map(
            static fn (Update $update): string => $update->getData(),
            $this->published,
        ));

        foreach ([self::EASY_A, self::EASY_B, self::MEDIUM, self::HARD] as $phrase) {
            self::assertStringNotContainsString($phrase, $broadcast);
        }
    }

    private function phraseFor(string $level): string
    {
        $choices = $this->engine->hostView($this->room)['choices'];
        self::assertIsArray($choices);

        $choice = $choices[$level] ?? null;
        self::assertIsArray($choice);

        $phrase = $choice['phrase'] ?? null;
        self::assertIsString($phrase);

        return $phrase;
    }

    private function hasChoiceLevel(string $level): bool
    {
        $choices = $this->engine->hostView($this->room)['choices'];
        self::assertIsArray($choices);

        return \array_key_exists($level, $choices);
    }

    private function expireTimer(): void
    {
        $this->redis->setex(RoomKeys::gameDeadline($this->room->code), self::TTL, (string) ((int) (microtime(true) * 1000) - 1000));
    }

    private function setRemaining(int $milliseconds): void
    {
        $this->redis->setex(
            RoomKeys::gameDeadline($this->room->code),
            self::TTL,
            (string) ((int) (microtime(true) * 1000) + $milliseconds),
        );
    }

    /** @param array<string, mixed> $payload */
    private function host(string $action, array $payload = []): void
    {
        $this->engine->handle($this->room, $action, $payload, Actor::host());
    }
}
