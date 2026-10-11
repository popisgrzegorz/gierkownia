<?php

declare(strict_types=1);

namespace App\Tests\Game\OstatniaLitera;

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
use App\Game\OstatniaLitera\OstatniaLiteraGame;
use App\Tests\Fixtures\FixedWordJudge;
use App\Tests\RedisConnection;
use App\Tests\RedisTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

/**
 * Ostatnia litera przez silnik.
 *
 * Sedno tej gry: wyscig do progu punktow (np. pierwszy do 50 wygrywa) z
 * eliminacja jako siatka bezpieczenstwa (0 punktow lub mniej = koniec dla
 * druzyny), trafienie warte tyle punktow, ile sekund zostalo na zegarze
 * w chwili odpowiedzi, i pauza na sedziego, gdy slownik (tu: atrapa
 * FixedWordJudge) nie rozpoznaje slowa - host rozstrzyga, gra nie zgaduje.
 */
final class OstatniaLiteraGameTest extends RedisTestCase
{
    use RedisConnection;

    private const TTL = 60;
    /** Musi zgadzac sie z OstatniaLiteraGame::TIMEOUT_PENALTY (prywatna, wiec zdublowana tutaj). */
    private const TIMEOUT_PENALTY = 2;
    /** @var list<string> */
    private const KNOWN_WORDS = ['kot', 'tor', 'rak', 'kod', 'dom', 'mak', 'koń', 'niebo', 'arbuz', 'ekran'];

    private GameEngine $engine;
    private GameState $gameState;
    private Scoreboard $scoreboard;
    private RoomManager $rooms;
    private Room $room;
    private Team $alfa;
    private Team $beta;
    private Team $gamma;

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
        $timer = new GameTimer($this->redis, $reader, self::TTL);

        $publisher = new Publisher(new MockHub(
            url: 'http://localhost/.well-known/mercure',
            jwtProvider: new StaticTokenProvider('test'),
            publisher: function (Update $update): string {
                $this->published[] = $update;

                return 'test-id';
            },
        ));

        $this->scoreboard = new Scoreboard($this->redis, $reader, $this->rooms, $publisher, self::TTL);

        $game = new OstatniaLiteraGame(
            $fsm,
            $this->gameState,
            $this->rooms,
            $this->scoreboard,
            $timer,
            new FixedWordJudge(self::KNOWN_WORDS),
            $publisher,
        );

        $this->engine = new GameEngine(new GameRegistry([$game]), $this->gameState, $fsm, $this->rooms, $publisher, new RoomLock($this->redis));

        $this->room = $this->rooms->create();
        $this->alfa = $this->rooms->join($this->room->code, 'Alfa');
        $this->beta = $this->rooms->join($this->room->code, 'Beta');
        $this->gamma = $this->rooms->join($this->room->code, 'Gamma');

        $this->engine->start($this->room, OstatniaLiteraGame::type());
    }

    public function testStartRefusesFewerThanTwoTeams(): void
    {
        $room = $this->rooms->create();
        $this->rooms->join($room->code, 'Sama');
        $this->engine->start($room, OstatniaLiteraGame::type());

        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessage('dwóch drużyn');

        $this->engine->handle($room, 'start', ['seconds' => 10], Actor::host());
    }

    public function testStartRefusesASecondsValueOutsideThePicklist(): void
    {
        $this->expectException(IllegalActionException::class);

        $this->host('start', ['seconds' => 12]);
    }

    #[DataProvider('validSeconds')]
    public function testStartAcceptsEachPicklistValue(int $seconds): void
    {
        $this->host('start', ['seconds' => $seconds]);

        self::assertSame('playing', $this->engine->hostView($this->room)['state']);
        self::assertSame($seconds, $this->engine->hostView($this->room)['seconds']);
    }

    /** @return iterable<string, array{int}> */
    public static function validSeconds(): iterable
    {
        yield '5' => [5];
        yield '10' => [10];
        yield '15' => [15];
        yield '20' => [20];
        yield '30' => [30];
    }

    /** Tablo ma pokazywac punkty startowe od pierwszej sekundy gry, nie dopiero po pierwszym zdarzeniu. */
    public function testStartSeedsScoreboardToDefaultStartingPointsForEveryTeam(): void
    {
        $this->start();

        self::assertSame(10, $this->scoreboard->get($this->room->code, $this->alfa->id));
        self::assertSame(10, $this->scoreboard->get($this->room->code, $this->beta->id));
        self::assertSame(10, $this->scoreboard->get($this->room->code, $this->gamma->id));
    }

    public function testStartAcceptsCustomStartingPointsAndWinThreshold(): void
    {
        $this->start(seconds: 10, startingPoints: 20, winThreshold: 80);

        $view = $this->engine->hostView($this->room);
        self::assertSame(20, $view['startingPoints']);
        self::assertSame(80, $view['winThreshold']);
        self::assertSame(20, $this->scoreboard->get($this->room->code, $this->alfa->id));
    }

    public function testStartRejectsAWinThresholdThatIsNotHigherThanStartingPoints(): void
    {
        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessage('wyższy niż punkty startowe');

        $this->start(seconds: 10, startingPoints: 30, winThreshold: 30);
    }

    public function testStartRejectsStartingPointsBelowTheAllowedMinimum(): void
    {
        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessage('Punkty startowe');

        $this->start(seconds: 10, startingPoints: 0, winThreshold: 50);
    }

    public function testSubmitRefusesTheHostAsActor(): void
    {
        $this->start();

        $this->expectException(IllegalActionException::class);

        $this->engine->handle($this->room, 'submit', ['word' => 'kot'], Actor::host());
    }

    public function testSubmitRefusesWhenItIsNotYourTurn(): void
    {
        $this->start();

        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessage('Nie twoja kolej.');

        $this->team($this->beta, 'submit', ['word' => 'kot']);
    }

    public function testSubmitRefusesAWordShorterThanTwoLetters(): void
    {
        $this->start();

        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessage('co najmniej dwie litery');

        $this->team($this->alfa, 'submit', ['word' => 'a']);
    }

    public function testSubmitRefusesTheWrongStartingLetter(): void
    {
        $this->start();
        $this->forceLastWord('kot');

        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessage('zaczynać się na „T”');

        $this->team($this->alfa, 'submit', ['word' => 'dom']);
    }

    /** Miekka spolgloska koncowa (ń) wymaga dwuznaku (ni) na starcie kolejnego slowa. */
    public function testSubmitRefusesAWrongStartAfterASoftConsonant(): void
    {
        $this->start();
        $this->forceLastWord('koń');

        $this->expectException(IllegalActionException::class);

        $this->team($this->alfa, 'submit', ['word' => 'noga']);
    }

    public function testSubmitAcceptsTheDigraphAfterASoftConsonant(): void
    {
        $this->start();
        $this->forceLastWord('koń');

        $this->team($this->alfa, 'submit', ['word' => 'niebo']);

        self::assertSame('niebo', $this->engine->hostView($this->room)['lastWord']);
    }

    /**
     * Zadne polskie slowo nie zaczyna sie na "ą", a "gęślą" konczy sie na "ą":
     * bez tej reguly gra stawala - obie druzyny traciły punkty za spoznienie
     * do konca partii (wynik na minusie).
     */
    public function testAfterANasalVowelTheBaseLetterStartsTheNextWord(): void
    {
        $this->start();
        $this->forceLastWord('gęślą');

        $this->team($this->alfa, 'submit', ['word' => 'arbuz']);

        self::assertSame('arbuz', $this->engine->hostView($this->room)['lastWord']);
    }

    public function testAfterTheNasalEThePlainEStartsTheNextWord(): void
    {
        $this->start();
        $this->forceLastWord('sprawę');

        $this->team($this->alfa, 'submit', ['word' => 'ekran']);

        self::assertSame('ekran', $this->engine->hostView($this->room)['lastWord']);
    }

    public function testAfterANasalVowelAnotherLetterIsStillRefusedAndTheMessageNamesTheBaseLetter(): void
    {
        $this->start();
        $this->forceLastWord('gęślą');

        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessage('„A”');

        $this->team($this->alfa, 'submit', ['word' => 'rak']);
    }

    public function testSubmitRefusesAnAlreadyUsedWordCaseInsensitively(): void
    {
        $this->start();
        // "tort" zaczyna i konczy sie na "t" - powtorka wciaz spelnia regule
        // litery startowej, wiec test naprawde sprawdza sam mechanizm
        // duplikatow, a nie wpada wczesniej na zla litere.
        $this->gameState->mergeCtx($this->room->code, ['lastWord' => 'tort', 'usedWords' => ['tort']]);

        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessage('już padło');

        $this->team($this->alfa, 'submit', ['word' => 'TORT']);
    }

    /** Powtorka slowa dostaje wlasny sygnal dla TV (komunikat + dzwiek) - reszta odrzuceń nie. */
    public function testDuplicateWordBroadcastsToHostForATvAnnouncement(): void
    {
        $this->start();
        $this->gameState->mergeCtx($this->room->code, ['lastWord' => 'tort', 'usedWords' => ['tort']]);

        try {
            $this->team($this->alfa, 'submit', ['word' => 'TORT']);
            self::fail('Powtorka slowa powinna zostac odrzucona.');
        } catch (IllegalActionException) {
            // Oczekiwane - sprawdzamy ponizej sam broadcast.
        }

        $event = $this->publishedEvent('word_duplicate');
        self::assertSame($this->alfa->id, $event['teamId']);
        self::assertSame('TORT', $event['word']);
    }

    public function testFirstWordOfTheGameHasNoStartingLetterConstraint(): void
    {
        $this->start();

        // Zaden lastWord jeszcze nie istnieje - dowolne slowo z listy przechodzi.
        $this->team($this->alfa, 'submit', ['word' => 'dom']);

        self::assertSame('dom', $this->engine->hostView($this->room)['lastWord']);
    }

    public function testAcceptedWordAdvancesTurnWithoutJudgingWhenRecognised(): void
    {
        $this->start();
        $this->setRemaining(3_000);

        $this->team($this->alfa, 'submit', ['word' => 'kot']);

        $view = $this->engine->hostView($this->room);
        self::assertSame('playing', $view['state']);
        self::assertSame($this->beta->id, $view['turn']);
        self::assertSame(['kot'], $view['usedWords']);
        self::assertSame(13, $this->scoreboard->get($this->room->code, $this->alfa->id));
    }

    /** Sedno wzoru: 10s na słowo, odpowiedź z 3s zapasu = +3 punkty (przykład z życzenia użytkownika). */
    public function testCorrectAnswerAwardsPointsEqualToWholeSecondsRemaining(): void
    {
        $this->start(seconds: 10);
        $this->setRemaining(3_000);

        $this->team($this->alfa, 'submit', ['word' => 'kot']);

        self::assertSame(13, $this->scoreboard->get($this->room->code, $this->alfa->id));
    }

    public function testCorrectAnswerRoundsTheRemainingTimeUp(): void
    {
        $this->start();
        $this->setRemaining(2_400);

        $this->team($this->alfa, 'submit', ['word' => 'kot']);

        // ceil(2.4) = 3, nie 2 - zgadza sie z tym, co druzyna widziala na zegarze.
        self::assertSame(13, $this->scoreboard->get($this->room->code, $this->alfa->id));
    }

    public function testCorrectAnswerNeverAwardsNegativePoints(): void
    {
        $this->start();
        $this->setRemaining(0);

        $this->team($this->alfa, 'submit', ['word' => 'kot']);

        self::assertSame(10, $this->scoreboard->get($this->room->code, $this->alfa->id));
    }

    public function testUnrecognisedWordPausesForTheHostToJudge(): void
    {
        $this->start();

        $this->team($this->alfa, 'submit', ['word' => 'niedowodzone']);

        $view = $this->engine->hostView($this->room);
        self::assertSame('judging', $view['state']);
        self::assertSame('niedowodzone', $view['pendingWord']);
        self::assertNull($view['deadline']);
    }

    /**
     * Punkty licza sie w CHWILI ZGLOSZENIA slowa, nie w chwili werdyktu -
     * inaczej zwlekajacy host oszukiwalby druzyne z naleznych punktow (zegar
     * i tak stoi przez cala narade, wiec "swiezy" odczyt dalby zero).
     */
    public function testHostAcceptingAPendingWordAwardsPointsCapturedAtSubmissionTime(): void
    {
        $this->start(seconds: 10);
        $this->setRemaining(3_000);
        $this->team($this->alfa, 'submit', ['word' => 'niedowodzone']);

        $this->host('rule', ['accept' => true]);

        $view = $this->engine->hostView($this->room);
        self::assertSame('playing', $view['state']);
        self::assertSame(['niedowodzone'], $view['usedWords']);
        self::assertSame(13, $this->scoreboard->get($this->room->code, $this->alfa->id));
        self::assertSame($this->beta->id, $view['turn']);
        self::assertNotNull($view['deadline']);
    }

    public function testHostRejectingAPendingWordDoesNotAwardPointsOrAdvance(): void
    {
        $this->start();
        $this->team($this->alfa, 'submit', ['word' => 'niedowodzone']);

        $this->host('rule', ['accept' => false]);

        $view = $this->engine->hostView($this->room);
        self::assertSame('playing', $view['state']);
        self::assertSame([], $view['usedWords']);
        self::assertSame(10, $this->scoreboard->get($this->room->code, $this->alfa->id));
        self::assertSame($this->alfa->id, $view['turn']);
    }

    public function testRuleIsRefusedOutsideJudgingState(): void
    {
        $this->start();

        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessage('werdykt');

        $this->host('rule', ['accept' => true]);
    }

    public function testTimeoutIsRefusedWhenTheClockHasNotExpired(): void
    {
        $this->start();

        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessage('Czas jeszcze nie minął.');

        $this->host('timeout');
    }

    public function testTimeoutCostsThePenaltyButKeepsTheTeamInWhenPointsRemain(): void
    {
        $this->start();
        $this->expireTimer();

        $this->host('timeout');

        $view = $this->engine->hostView($this->room);
        self::assertSame([], $view['eliminated']);
        self::assertSame($this->beta->id, $view['turn']);
        self::assertSame(10 - self::TIMEOUT_PENALTY, $this->scoreboard->get($this->room->code, $this->alfa->id));
    }

    /** Kolejnosc alfa/beta/gamma - z wyeliminowana beta w srodku, tura z alfy ma isc na gamme. */
    public function testAcceptedWordSkipsAnEliminatedTeamInTheMiddleOfTheOrder(): void
    {
        $this->start();
        $this->gameState->mergeCtx($this->room->code, ['eliminated' => [$this->beta->id]]);

        $this->team($this->alfa, 'submit', ['word' => 'kot']);

        self::assertSame($this->gamma->id, $this->engine->hostView($this->room)['turn']);
    }

    /** Tablo ma odzwierciedlac punkty: wyeliminowany dokladnie 0, przeciwnik nietkniety zostaje przy starcie. */
    public function testTimeoutWithExactlyTwoTeamsEndsTheGameImmediately(): void
    {
        $room = $this->rooms->create();
        $a = $this->rooms->join($room->code, 'A');
        $b = $this->rooms->join($room->code, 'B');
        $this->engine->start($room, OstatniaLiteraGame::type());
        $this->engine->handle($room, 'start', ['seconds' => 5], Actor::host());

        $this->exhaust($b, $room);

        $view = $this->engine->hostView($room);
        self::assertSame('finished', $view['state']);
        self::assertSame($a->id, $view['winner']);
        self::assertSame(0, $this->scoreboard->get($room->code, $b->id));
        self::assertSame(10, $this->scoreboard->get($room->code, $a->id));
    }

    /** Tablo mirroruje punkty na biezaco - wyeliminowani przy zerze (remis), zwyciezca przy swoich nietknietych punktach. */
    public function testFullEliminationSequenceLeavesEliminatedTeamsAtZeroAndWinnerAboveThem(): void
    {
        $this->start();

        $this->exhaust($this->alfa);
        $this->exhaust($this->beta);

        $view = $this->engine->hostView($this->room);
        self::assertSame('finished', $view['state']);
        self::assertSame($this->gamma->id, $view['winner']);

        self::assertSame(0, $this->scoreboard->get($this->room->code, $this->alfa->id));
        self::assertSame(0, $this->scoreboard->get($this->room->code, $this->beta->id));
        self::assertSame(10, $this->scoreboard->get($this->room->code, $this->gamma->id));
    }

    /** Drugi koniec gry: pierwszy do progu wygrywa natychmiast, bez czekania na eliminacje reszty. */
    public function testReachingTheWinThresholdEndsTheGameImmediately(): void
    {
        $this->start(seconds: 10, startingPoints: 8, winThreshold: 10);
        $this->setRemaining(5_000);

        $this->team($this->alfa, 'submit', ['word' => 'kot']);

        $view = $this->engine->hostView($this->room);
        self::assertSame('finished', $view['state']);
        self::assertSame($this->alfa->id, $view['winner']);
        self::assertSame(13, $this->scoreboard->get($this->room->code, $this->alfa->id));
    }

    /** Ten sam próg, ale osiągnięty przez werdykt hosta, nie auto-akceptację. */
    public function testReachingTheWinThresholdViaJudgeAcceptanceEndsTheGame(): void
    {
        $this->start(seconds: 10, startingPoints: 8, winThreshold: 10);
        $this->setRemaining(5_000);
        $this->team($this->alfa, 'submit', ['word' => 'niedowodzone']);

        $this->host('rule', ['accept' => true]);

        $view = $this->engine->hostView($this->room);
        self::assertSame('finished', $view['state']);
        self::assertSame($this->alfa->id, $view['winner']);
        self::assertSame(13, $this->scoreboard->get($this->room->code, $this->alfa->id));
    }

    public function testActionsAfterFinishAreRefused(): void
    {
        $room = $this->rooms->create();
        $this->rooms->join($room->code, 'A');
        $b = $this->rooms->join($room->code, 'B');
        $this->engine->start($room, OstatniaLiteraGame::type());
        $this->engine->handle($room, 'start', ['seconds' => 5], Actor::host());
        $this->exhaust($b, $room);

        $this->expectException(IllegalActionException::class);

        $this->engine->handle($room, 'timeout', [], Actor::host());
    }

    public function testRestartReturnsToIdleAndStartFullyResetsContext(): void
    {
        $room = $this->rooms->create();
        $a = $this->rooms->join($room->code, 'A');
        $b = $this->rooms->join($room->code, 'B');
        $this->engine->start($room, OstatniaLiteraGame::type());
        $this->engine->handle($room, 'start', ['seconds' => 5], Actor::host());
        $this->exhaust($b, $room);

        $this->engine->handle($room, 'restart', [], Actor::host());
        self::assertSame('idle', $this->engine->hostView($room)['state']);

        $this->engine->handle($room, 'start', ['seconds' => 10], Actor::host());
        $view = $this->engine->hostView($room);

        self::assertSame(10, $this->scoreboard->get($room->code, $a->id));
        self::assertSame(10, $this->scoreboard->get($room->code, $b->id));
        self::assertSame([], $view['eliminated']);
        self::assertSame([], $view['usedWords']);
        self::assertNull($view['winner']);
    }

    public function testTimerEventCarriesTheChosenSeconds(): void
    {
        $this->host('start', ['seconds' => 20]);

        $event = $this->publishedEvent('timer');

        self::assertIsInt($event['deadline']);
        self::assertIsInt($event['serverNow']);
        self::assertSame(20, $event['seconds']);
    }

    private function start(int $seconds = 10, ?int $startingPoints = null, ?int $winThreshold = null): void
    {
        $payload = ['seconds' => $seconds];

        if (null !== $startingPoints) {
            $payload['startingPoints'] = $startingPoints;
        }

        if (null !== $winThreshold) {
            $payload['winThreshold'] = $winThreshold;
        }

        $this->host('start', $payload);
    }

    private function forceLastWord(string $word): void
    {
        $this->gameState->mergeCtx($this->room->code, ['lastWord' => $word]);
    }

    /**
     * Stawia druzyne tuz przed odpadnieciem (tyle punktow, ile wynosi kara za
     * spoznienie) i oddaje jej ture, potem wywoluje jedno prawdziwe spoznienie -
     * testuje sam mechanizm eliminacji, bez symulowania wczesniejszych rund.
     */
    private function exhaust(Team $team, ?Room $room = null): void
    {
        $room ??= $this->room;

        $current = $this->scoreboard->get($room->code, $team->id);
        $this->scoreboard->add($room->code, $team->id, self::TIMEOUT_PENALTY - $current);

        $this->gameState->mergeCtx($room->code, ['turn' => $team->id]);

        $this->expireTimer($room);
        $this->engine->handle($room, 'timeout', [], Actor::host());
    }

    /** Przestawia termin w przeszlosc - inaczej test czekalby na prawdziwy zegar. */
    private function expireTimer(?Room $room = null): void
    {
        $room ??= $this->room;

        $this->redis->setex(
            RoomKeys::gameDeadline($room->code),
            self::TTL,
            (string) ((int) (microtime(true) * 1000) - 1000),
        );
    }

    /** Ustawia pozostały czas na dokładnie tyle milisekund - deterministyczne testy wzoru na punkty. */
    private function setRemaining(int $milliseconds, ?Room $room = null): void
    {
        $room ??= $this->room;

        $this->redis->setex(
            RoomKeys::gameDeadline($room->code),
            self::TTL,
            (string) ((int) (microtime(true) * 1000) + $milliseconds),
        );
    }

    /**
     * Ostatnie rozgloszone zdarzenie danego typu, zdekodowane z JSON-a.
     *
     * @return array<string, mixed>
     */
    private function publishedEvent(string $type): array
    {
        foreach (array_reverse($this->published) as $update) {
            /** @var array<string, mixed> $data */
            $data = json_decode($update->getData(), true, flags: \JSON_THROW_ON_ERROR);

            if (($data['type'] ?? null) === $type) {
                return $data;
            }
        }

        self::fail(sprintf('Nie rozgloszono zadania typu "%s".', $type));
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
