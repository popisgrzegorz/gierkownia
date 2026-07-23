<?php

declare(strict_types=1);

namespace App\Tests\Game\MelodiaQuiz;

use App\Content\TrackDeck;
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
use App\Core\Timer\GameTimer;
use App\Entity\Category;
use App\Entity\Track;
use App\Game\MelodiaQuiz\MelodiaQuizGame;
use App\Repository\CategoryRepository;
use App\Repository\TrackRepository;
use App\Tests\DoctrineTestCase;
use App\Tests\RedisConnection;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

/**
 * Test melodii 1 z 4 przez silnik.
 *
 * Najwazniejsze nie jest liczenie punktow, tylko dwie rzeczy, ktore psuja
 * zabawe przy stole: wyciek poprawnej odpowiedzi przed czasem i odpowiedz
 * dopisana po zamknieciu rundy.
 */
final class MelodiaQuizGameTest extends DoctrineTestCase
{
    use RedisConnection;

    private const TTL = 60;

    private GameEngine $engine;
    private GameState $gameState;
    private Scoreboard $scoreboard;
    private GameTimer $timer;
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
        $this->timer = new GameTimer($this->redis, $reader, self::TTL);

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

        $deck = new TrackDeck(
            self::getContainer()->get(CategoryRepository::class),
            self::getContainer()->get(TrackRepository::class),
        );

        $quiz = new MelodiaQuizGame($fsm, $this->gameState, $deck, $this->scoreboard, $this->timer, $this->rooms);

        $this->engine = new GameEngine(new GameRegistry([$quiz]), $this->gameState, $fsm, $this->rooms, $publisher);

        $this->room = $this->rooms->create();
        $this->kanapowcy = $this->rooms->join($this->room->code, 'Kanapowcy');
        $this->dywanowi = $this->rooms->join($this->room->code, 'Dywanowi');

        $this->engine->start($this->room, MelodiaQuizGame::type());
        $this->host('pick_category', ['category' => 'testowa']);
        $this->host('next_track');
        $this->host('start', ['seconds' => 30]);
    }

    /** Kategoria z czterema nagraniami - mniej nie wystarczy na test 1 z 4. */
    private function seedCategory(): void
    {
        $category = new Category('testowa', 'Kategoria testowa', Category::KIND_MELODIA);
        $this->entityManager->persist($category);

        foreach (['Pierwszy', 'Drugi', 'Trzeci', 'Czwarty', 'Piąty'] as $i => $title) {
            $track = new Track($category, sprintf('demo/%d.wav', $i), $title, 'Tradycyjne');
            $track->setPosition($i);
            $this->entityManager->persist($track);
        }

        $this->entityManager->flush();
    }

    public function testRoundStartsWithFourOptionsAndOneOfThemIsRight(): void
    {
        $view = $this->engine->playerView($this->room, $this->kanapowcy);

        self::assertSame('answering', $view['state']);
        self::assertIsArray($view['options']);
        self::assertCount(4, $view['options']);
        self::assertNotNull($view['deadline']);
    }

    /**
     * Odpowiedz to "WYKONAWCA - Tytul", nie sam tytul: przy coverach tylko
     * wykonawca odroznia wykonania, a wersaliki widac z kanapy.
     */
    public function testEveryOptionCarriesTheArtistInCaps(): void
    {
        $options = $this->engine->hostView($this->room)['options'];

        self::assertIsArray($options);

        foreach ($options as $option) {
            self::assertIsString($option);
            self::assertStringStartsWith('TRADYCYJNE — ', $option);
        }
    }

    /**
     * Sedno tej gry: dopoki trwa runda, poprawna odpowiedz nie wychodzi
     * z serwera - ani na telefon, ani na telewizor, ani rozgloszeniem.
     * Inaczej wystarczyloby zajrzec w narzedzia deweloperskie.
     */
    public function testTheRightAnswerNeverLeaksBeforeTheRoundEnds(): void
    {
        self::assertNull($this->engine->playerView($this->room, $this->kanapowcy)['correct']);
        self::assertNull($this->engine->hostView($this->room)['correct']);

        // Update trzeba rozpakowac przez getData(): json_encode samych obiektow
        // daje "[{}]", wiec asercja przechodzilaby zawsze, niczego nie sprawdzajac.
        $published = implode(' ', array_map(
            static fn (Update $update): string => $update->getData(),
            $this->published,
        ));

        self::assertStringNotContainsString('_correct', $published);
        self::assertStringNotContainsString('"correct"', $published);
    }

    /** Cudza odpowiedz to tez sekret - kazdy widzi wylacznie swoja. */
    public function testTeamSeesOnlyItsOwnAnswer(): void
    {
        $this->team($this->kanapowcy, 'answer', ['option' => $this->correctIndex()]);

        self::assertSame($this->correctIndex(), $this->engine->playerView($this->room, $this->kanapowcy)['myAnswer']);
        self::assertNull($this->engine->playerView($this->room, $this->dywanowi)['myAnswer']);
    }

    /** Liczy sie pierwsza odpowiedz - inaczej wskaznik "kto juz odpowiedzial" klamie. */
    public function testAnswerIsLockedAfterTheFirstOne(): void
    {
        $this->team($this->kanapowcy, 'answer', ['option' => 0]);

        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessage('już wysłana');

        $this->team($this->kanapowcy, 'answer', ['option' => 1]);
    }

    public function testAnswerAfterTheDeadlineIsRefused(): void
    {
        $this->expireTimer();

        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessage('Czas minął.');

        $this->team($this->kanapowcy, 'answer', ['option' => 0]);
    }

    /**
     * Gdy odpowiedza wszyscy, runda konczy sie sama. Czekanie do konca zegara,
     * kiedy nie ma juz na kogo czekac, zabija tempo wieczoru.
     */
    public function testRoundClosesItselfWhenEveryoneHasAnswered(): void
    {
        $correct = $this->correctIndex();

        $this->team($this->kanapowcy, 'answer', ['option' => $correct]);
        self::assertSame('answering', $this->engine->currentState($this->room));

        $this->team($this->dywanowi, 'answer', ['option' => ($correct + 1) % 4]);

        self::assertSame('results', $this->engine->currentState($this->room));
        self::assertSame(10, $this->scoreboard->get($this->room->code, $this->kanapowcy->id));
        self::assertSame(0, $this->scoreboard->get($this->room->code, $this->dywanowi->id));
    }

    /** Po rundzie tytul i cudze odpowiedzi sa juz jawne - zagadka rozwiazana. */
    public function testResultsRevealTheTitleAndWhoPickedWhat(): void
    {
        $correct = $this->correctIndex();
        $options = $this->engine->hostView($this->room)['options'];

        self::assertIsArray($options);

        $this->team($this->kanapowcy, 'answer', ['option' => $correct]);
        $this->team($this->dywanowi, 'answer', ['option' => ($correct + 1) % 4]);

        $view = $this->engine->hostView($this->room);

        self::assertSame($correct, $view['correct']);
        self::assertSame($options[$correct], $view['revealed']);
        self::assertSame([$this->kanapowcy->id], $view['winners']);
    }

    /** Nikt nie trafil - ten sam utwor wraca do gry. */
    public function testTrackCanBeReplayedWhenNobodyGotIt(): void
    {
        $wrong = ($this->correctIndex() + 1) % 4;

        $this->team($this->kanapowcy, 'answer', ['option' => $wrong]);
        $this->team($this->dywanowi, 'answer', ['option' => $wrong]);

        $this->host('again');

        $view = $this->engine->playerView($this->room, $this->kanapowcy);

        self::assertSame('answering', $view['state']);
        self::assertSame(2, $view['attempt']);
        self::assertNull($view['myAnswer']);
    }

    /** Powtorka po trafieniu bylaby druga szansa na te same punkty. */
    public function testReplayIsRefusedWhenSomebodyWasRight(): void
    {
        $correct = $this->correctIndex();

        $this->team($this->kanapowcy, 'answer', ['option' => $correct]);
        $this->team($this->dywanowi, 'answer', ['option' => $correct]);

        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessage('powtórka nie ma sensu');

        $this->host('again');
    }

    /**
     * "Nastepny utwor" losuje od razu, od czystej kartki. Osobne kliknięcie
     * "Losuj" bylo pusta przerwa - host i tak zawsze klikal je zaraz potem.
     */
    public function testNextRoundDrawsTheNextTrackAtOnce(): void
    {
        $this->playRound();

        $this->host('next_round');

        $view = $this->engine->playerView($this->room, $this->kanapowcy);

        self::assertSame('ready', $view['state']);
        self::assertIsArray($view['options']);
        self::assertCount(4, $view['options']);
        self::assertSame(2, $view['trackNumber']);
        self::assertNull($view['myAnswer']);
        self::assertNull($view['correct']);
    }

    /**
     * Kategoria konczy sie w trakcie wieczoru. "Nastepny utwor" ma wtedy
     * spokojnie wrocic do wyboru kategorii, a nie wywalic akcje po tym, jak
     * runda zostala juz zamknieta - inaczej pokoj utknalby na wynikach.
     */
    public function testAnExhaustedCategoryLeavesTheRoomIdle(): void
    {
        $this->playRound();

        // W kategorii testowej jest piec nagran, pierwsze poszlo w setUp.
        for ($i = 2; $i <= 5; ++$i) {
            $this->host('next_round');
            $this->host('start');
            $this->playRound();
        }

        $this->host('next_round');

        self::assertSame('idle', $this->engine->currentState($this->room));
        self::assertSame([], $this->engine->hostView($this->room)['options']);
    }

    /** Obie druzyny odpowiadaja - runda zamyka sie sama. */
    private function playRound(): void
    {
        $correct = $this->correctIndex();

        $this->team($this->kanapowcy, 'answer', ['option' => $correct]);
        $this->team($this->dywanowi, 'answer', ['option' => $correct]);
    }

    /** Odpowiedz spoza czworki nie moze wejsc do kontekstu gry. */
    public function testOptionOutsideTheRangeIsRefused(): void
    {
        $this->expectException(IllegalActionException::class);

        $this->team($this->kanapowcy, 'answer', ['option' => 9]);
    }

    /** Poprawna odpowiedz jest w kontekscie prywatnym, ale test musi ja znac. */
    private function correctIndex(): int
    {
        $correct = $this->gameState->ctx($this->room->code)['_correct'] ?? null;

        self::assertIsInt($correct);

        return $correct;
    }

    private function expireTimer(): void
    {
        $this->redis->setex(
            sprintf('room:%s:game:deadline', $this->room->code),
            self::TTL,
            (string) ((int) (microtime(true) * 1000) - 1000),
        );
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
}
