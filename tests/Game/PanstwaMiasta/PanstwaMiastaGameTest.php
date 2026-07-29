<?php

declare(strict_types=1);

namespace App\Tests\Game\PanstwaMiasta;

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
use App\Core\Room\RoomLock;
use App\Core\Room\RoomManager;
use App\Core\Room\Team;
use App\Core\Score\Scoreboard;
use App\Core\Timer\GameTimer;
use App\Game\PanstwaMiasta\PanstwaMiastaGame;
use App\Tests\RedisConnection;
use App\Tests\RedisTestCase;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

/**
 * Panstwa i miasta przez silnik.
 *
 * Najwazniejsze tutaj nie jest liczenie punktow (to ma swoj test), tylko dwie
 * rzeczy, ktore psuja zabawe przy stole: wyciek cudzych odpowiedzi w trakcie
 * pisania i odpowiedz dopisana po czasie.
 */
final class PanstwaMiastaGameTest extends RedisTestCase
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

        $this->scoreboard = new Scoreboard($this->redis, $reader, $this->rooms, $publisher, self::TTL);

        $game = new PanstwaMiastaGame(
            $fsm,
            $this->gameState,
            $this->rooms,
            $this->scoreboard,
            $this->timer,
        );

        $this->engine = new GameEngine(new GameRegistry([$game]), $this->gameState, $fsm, $this->rooms, $publisher, new RoomLock($this->redis));

        $this->room = $this->rooms->create();
        $this->kanapowcy = $this->rooms->join($this->room->code, 'Kanapowcy');
        $this->dywanowi = $this->rooms->join($this->room->code, 'Dywanowi');

        $this->engine->start($this->room, PanstwaMiastaGame::type());
        $this->host('configure', ['preset' => 'krotkie', 'seconds' => 30]);
        $this->host('draw');
    }

    public function testDrawingALetterOpensWritingWithColumns(): void
    {
        $view = $this->engine->playerView($this->room, $this->kanapowcy);

        self::assertSame('writing', $view['state']);
        self::assertNotNull($view['letter']);
        self::assertSame(['Państwo', 'Miasto', 'Zwierzę', 'Rzecz'], $view['columns']);
        self::assertTrue($view['canWrite']);
    }

    /**
     * Telefon odslania litere w chwili revealAt, nie po wlasnym, zgadywanym
     * opoznieniu - inaczej dwa niezalezne zegary (telefonu i TV) rozjezdzaly
     * sie o tyle, ze gracz z telefonem widzial litere, zanim spadla ostatnia
     * klapka na TV (P2-12 audytu). revealAt musi byc PO serverNow o dokladnie
     * tyle, ile realnie trwa hamowanie benba na TV plus zapas na jitter.
     */
    public function testLetterDrawnCarriesAServerRevealDeadline(): void
    {
        $event = $this->publishedEvent('letter_drawn');

        self::assertIsInt($event['serverNow']);
        self::assertIsInt($event['revealAt']);
        self::assertSame(2200, $event['revealAt'] - $event['serverNow']);
    }

    /**
     * Sedno tej gry: telefon widzi wylacznie swoje odpowiedzi. Gdyby cudze
     * wychodzily w widoku, wystarczyloby zajrzec w narzedzia deweloperskie,
     * zeby przepisac kartke sasiada.
     */
    public function testTeamSeesOnlyItsOwnAnswers(): void
    {
        $this->team($this->kanapowcy, 'answer', ['column' => 'Miasto', 'text' => 'Kraków']);

        self::assertSame('Kraków', $this->answersOf($this->kanapowcy)['Miasto'] ?? null);
        self::assertSame([], $this->answersOf($this->dywanowi));

        // I nic z tresci nie moze wyciec rozgloszeniem ani do widoku hosta.
        self::assertStringNotContainsString('Kraków', json_encode($this->published, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('Kraków', json_encode($this->engine->hostView($this->room), \JSON_THROW_ON_ERROR));
    }

    /** Host widzi postep, ale wylacznie jako liczbe wypelnionych rubryk. */
    public function testHostSeesProgressWithoutContent(): void
    {
        $this->team($this->kanapowcy, 'answer', ['column' => 'Miasto', 'text' => 'Kraków']);
        $this->team($this->kanapowcy, 'answer', ['column' => 'Rzecz', 'text' => 'Krzesło']);

        $view = $this->engine->hostView($this->room);
        $progress = $view['progress'];

        self::assertIsArray($progress);
        self::assertSame(2, $progress[$this->kanapowcy->id] ?? null);
        self::assertNull($view['results']);
    }

    public function testStopStartsTheCountdownForEveryone(): void
    {
        $this->fillCard($this->kanapowcy);
        $this->team($this->kanapowcy, 'stop');

        $view = $this->engine->playerView($this->room, $this->dywanowi);

        self::assertSame('ending', $view['state']);
        self::assertSame($this->kanapowcy->id, $view['stoppedBy']);
        self::assertNotNull($view['deadline']);
        // Druzyna, ktora nie zdazyla, wciaz moze pisac - ale juz z zegarem.
        self::assertTrue($view['canWrite']);
    }

    /**
     * STOP z niepelna kartka to zagranie kosztem reszty: wpisuje jedno slowo
     * i ucinam wszystkim czas. Blokada jest po stronie serwera, bo wyszarzony
     * przycisk omija sie jednym zadaniem.
     */
    public function testStopNeedsEveryFieldFilled(): void
    {
        $letter = $this->letter();
        $this->team($this->kanapowcy, 'answer', ['column' => 'Miasto', 'text' => $letter.'ielce']);

        try {
            $this->team($this->kanapowcy, 'stop');
            self::fail('STOP z niepelna kartka nie powinien przejsc.');
        } catch (IllegalActionException $e) {
            self::assertStringContainsString('wszystkie rubryki', $e->getMessage());
        }

        // Komplet - i ten sam STOP przechodzi.
        foreach (['Państwo', 'Zwierzę', 'Rzecz'] as $column) {
            $this->team($this->kanapowcy, 'answer', ['column' => $column, 'text' => $letter.'aaa']);
        }

        $this->team($this->kanapowcy, 'stop');

        self::assertSame('ending', $this->engine->hostView($this->room)['state']);
    }

    /** Host konczy runde jako prowadzacy, nie jako gracz - jego kartki nie ma. */
    public function testHostCanStopWithoutFillingAnything(): void
    {
        $this->host('stop');

        self::assertSame('ending', $this->engine->hostView($this->room)['state']);
    }

    /**
     * STOP zamyka wlasna kartke. Inaczej bylby zagraniem czysto taktycznym:
     * odcinam reszcie czas, a sam dopisuje przez cale odliczanie.
     */
    public function testTeamThatPressedStopCannotWriteAnyMore(): void
    {
        $this->fillCard($this->kanapowcy);
        $this->team($this->kanapowcy, 'stop');

        self::assertFalse($this->engine->playerView($this->room, $this->kanapowcy)['canWrite']);
        self::assertTrue($this->engine->playerView($this->room, $this->dywanowi)['canWrite']);

        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessage('wasze odpowiedzi są zamknięte');

        $this->team($this->kanapowcy, 'answer', ['column' => 'Miasto', 'text' => 'Kraków']);
    }

    /**
     * Po odliczeniu nie wchodzi ani jedno slowo. Telefon z zablokowanym ekranem
     * potrafi obudzic sie po minucie i dosylac to, co zdazyl wpisac.
     */
    public function testAnswerAfterTheDeadlineIsRefused(): void
    {
        $this->fillCard($this->kanapowcy);
        $this->team($this->kanapowcy, 'stop');
        $this->expireTimer();

        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessage('Czas minął.');

        $this->team($this->dywanowi, 'answer', ['column' => 'Miasto', 'text' => 'Gdańsk']);
    }

    /** Host nie utnie pisania w polowie odliczania - o tym decyduje zegar serwera. */
    public function testHostCannotFinishWhileTheClockIsRunning(): void
    {
        $this->fillCard($this->kanapowcy);
        $this->team($this->kanapowcy, 'stop');

        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessage('Odliczanie jeszcze trwa.');

        $this->host('finish');
    }

    public function testResultsAppearOnlyAfterTheRoundIsOver(): void
    {
        $this->forceLetter('K');

        $this->team($this->kanapowcy, 'answer', ['column' => 'Miasto', 'text' => 'Kielce']);
        $this->team($this->dywanowi, 'answer', ['column' => 'Miasto', 'text' => 'Kielce']);
        $this->team($this->kanapowcy, 'answer', ['column' => 'Rzecz', 'text' => 'Krzesło']);
        $this->team($this->kanapowcy, 'answer', ['column' => 'Państwo', 'text' => 'Kanada']);
        $this->team($this->kanapowcy, 'answer', ['column' => 'Zwierzę', 'text' => 'Kot']);

        $this->team($this->kanapowcy, 'stop');
        $this->expireTimer();
        $this->host('finish');

        self::assertSame('scoring', $this->engine->hostView($this->room)['state']);

        // To samo miasto u obu druzyn - po piec punktow; trzy pozostale rubryki
        // ma tylko jedna z nich, wiec po dziesiec.
        $totals = $this->totals();
        self::assertSame(35, $totals[$this->kanapowcy->id] ?? null);
        self::assertSame(5, $totals[$this->dywanowi->id] ?? null);
    }

    /** Korekta hosta przelicza wynik od razu, jeszcze przed zaliczeniem punktow. */
    public function testHostCorrectionChangesPointsBeforeTheyAreAwarded(): void
    {
        $this->fillAndFinish();

        $before = $this->totals()[$this->kanapowcy->id] ?? null;

        $this->host('toggle', ['teamId' => $this->kanapowcy->id, 'column' => 'Miasto']);

        $after = $this->totals()[$this->kanapowcy->id] ?? null;

        self::assertSame(10, $before);
        self::assertSame(0, $after);
        self::assertSame(0, $this->scoreboard->get($this->room->code, $this->kanapowcy->id));
    }

    public function testAwardingPutsRoundPointsOnTheScoreboard(): void
    {
        $this->fillAndFinish();
        $this->host('award');

        self::assertSame(10, $this->scoreboard->get($this->room->code, $this->kanapowcy->id));
    }

    /**
     * Tablo jest wspolne dla calego wieczoru, wiec podwojne klikniecie
     * "zalicz punkty" dopisaloby runde drugi raz.
     */
    public function testPointsCannotBeAwardedTwice(): void
    {
        $this->fillAndFinish();
        $this->host('award');

        $this->expectException(IllegalActionException::class);

        $this->host('award');
    }

    public function testNextRoundClearsTheCard(): void
    {
        $this->fillAndFinish();
        $this->host('award');
        $this->host('next_round');

        $view = $this->engine->playerView($this->room, $this->kanapowcy);

        self::assertSame('idle', $view['state']);
        self::assertNull($view['letter']);
        self::assertFalse($view['canWrite']);

        // Nowa runda to czysta kartka - inaczej poprzednie odpowiedzi
        // policzylyby sie drugi raz.
        $this->host('draw');
        self::assertSame([], $this->answersOf($this->kanapowcy));
    }

    /** Rubryki spoza zestawu nie moga wejsc do kontekstu gry. */
    public function testUnknownColumnIsRefused(): void
    {
        $this->expectException(IllegalActionException::class);

        $this->team($this->kanapowcy, 'answer', ['column' => 'Zawód', 'text' => 'Kominiarz']);
    }

    /** @return array<mixed, mixed> odpowiedzi druzyny w biezacej rundzie */
    private function answersOf(Team $team): array
    {
        $answers = $this->engine->playerView($this->room, $team)['answers'];

        self::assertIsArray($answers);

        return $answers;
    }

    /** @return array<mixed, mixed> teamId => punkty rundy */
    private function totals(): array
    {
        $results = $this->engine->hostView($this->room)['results'];

        self::assertIsArray($results);

        $totals = $results['totals'];

        self::assertIsArray($totals);

        return $totals;
    }

    private function fillAndFinish(): void
    {
        $this->forceLetter('K');

        $this->team($this->kanapowcy, 'answer', ['column' => 'Miasto', 'text' => 'Kielce']);
        $this->host('stop');
        $this->expireTimer();
        $this->host('finish');
    }

    /** Komplet rubryk - bez niego druzyna nie ma prawa nacisnac STOP. */
    /**
     * Wypelnia kartke odpowiedziami, ktore przechodza automat: prawdziwe
     * panstwo i miasto (rubryki z wykazem), reszta byle jak, byle na litere
     * i dluzsza niz jedna litera.
     */
    private function fillCard(Team $team): void
    {
        $this->forceLetter('K');

        $card = ['Państwo' => 'Kanada', 'Miasto' => 'Kielce', 'Zwierzę' => 'Kot', 'Rzecz' => 'Krzesło'];

        foreach ($card as $column => $text) {
            $this->team($team, 'answer', ['column' => $column, 'text' => $text]);
        }
    }

    /**
     * Ustawia wylosowana litere na stale.
     *
     * Bez tego testy musialyby budowac odpowiedzi z losowej litery ("Tanada"),
     * a te odrzuca teraz wykaz panstw i miast - i sluszne.
     */
    private function forceLetter(string $letter): void
    {
        $this->gameState->mergeCtx($this->room->code, ['letter' => $letter]);
    }

    private function letter(): string
    {
        $letter = $this->engine->hostView($this->room)['letter'];

        self::assertIsString($letter);

        return $letter;
    }

    /** Przestawia termin w przeszlosc - inaczej test czekalby trzydziesci sekund. */
    private function expireTimer(): void
    {
        $this->redis->setex(
            sprintf('room:%s:game:deadline', $this->room->code),
            self::TTL,
            (string) ((int) (microtime(true) * 1000) - 1000),
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
