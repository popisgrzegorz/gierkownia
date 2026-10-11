<?php

declare(strict_types=1);

namespace App\Tests\Game\Melodia;

use App\Core\Buzzer\BuzzerArbiter;
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
use App\Entity\Category;
use App\Entity\Track;
use App\Game\Melodia\MelodiaGame;
use App\Content\TrackDeck;
use App\Repository\CategoryRepository;
use App\Repository\TrackRepository;
use App\Tests\DoctrineTestCase;
use App\Tests\RedisConnection;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

/**
 * Pelna runda melodii przez silnik - tak, jak poleci z kontrolera.
 *
 * Testujemy modul przez GameEngine, a nie w izolacji: to wlasnie na styku
 * modulu z rdzeniem (przejscia FSM, publikacja, punkty) siedza bledy, ktore
 * przy stole objawiaja sie jako "buzzery nie gasna" albo "punkt nie doszedl".
 */
final class MelodiaGameTest extends DoctrineTestCase
{
    use RedisConnection;

    private const TTL = 60;

    private GameEngine $engine;
    private BuzzerArbiter $buzzer;
    private Scoreboard $scoreboard;
    private GameState $gameState;
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

        $this->seedCategory();

        $this->buzzer = new BuzzerArbiter($this->redis, $reader, $publisher, self::TTL);
        $this->scoreboard = new Scoreboard($this->redis, $reader, $this->rooms, $publisher, self::TTL);

        $categories = self::getContainer()->get(CategoryRepository::class);
        $tracks = self::getContainer()->get(TrackRepository::class);

        $melodia = new MelodiaGame($fsm, $this->gameState, $this->buzzer, $this->scoreboard, new TrackDeck($categories, $tracks));

        $this->engine = new GameEngine(
            new GameRegistry([$melodia]),
            $this->gameState,
            $fsm,
            $this->rooms,
            $publisher,
            new RoomLock($this->redis),
        );

        $this->room = $this->rooms->create();
        $this->kanapowcy = $this->rooms->join($this->room->code, 'Kanapowcy');
        $this->dywanowi = $this->rooms->join($this->room->code, 'Dywanowi');

        $this->engine->start($this->room, 'melodia');

        // Kazda runda zaczyna sie od talii i wylosowanego utworu - bez nich
        // host nie ma czego puscic, wiec 'arm' slusznie odmawia.
        $this->host('pick_category', ['category' => 'testowa']);
        $this->host('next_track');
    }

    /** Kategoria w bazie testowej; transakcja z DoctrineTestCase wycofa ja po tescie. */
    private function seedCategory(): void
    {
        $category = new Category('testowa', 'Kategoria testowa', 'melodia');
        $this->entityManager->persist($category);

        foreach ([['demo/wlazl-kotek.wav', 'Wlazl kotek', 'Tradycyjne'], ['demo/sto-lat.wav', 'Sto lat', 'Tradycyjne']] as $i => [$path, $title, $artist]) {
            $track = new Track($category, $path, $title, $artist);
            $track->setPosition($i);
            $this->entityManager->persist($track);
        }

        $this->entityManager->flush();
    }

    /** Kryterium akceptacji ze spec 16, przejscie od uzbrojenia do punktu. */
    public function testFullRoundFromArmToPoint(): void
    {
        self::assertSame('idle', $this->engine->currentState($this->room));

        $this->host('arm');
        self::assertSame('armed', $this->engine->currentState($this->room));
        self::assertTrue($this->buzzer->isArmed($this->room->code));

        $this->team($this->kanapowcy, 'buzz');
        self::assertSame('buzzed', $this->engine->currentState($this->room));

        $this->host('judge', ['correct' => true]);
        self::assertSame('judging', $this->engine->currentState($this->room));
        self::assertSame(1, $this->scoreboard->get($this->room->code, $this->kanapowcy->id));

        $this->host('finish');
        self::assertSame('idle', $this->engine->currentState($this->room));
    }

    /** Zwycieski buzz musi zgasic buzzery - inaczej druga druzyna tez "ma glos". */
    public function testWinningBuzzClosesTheRound(): void
    {
        $this->host('arm');
        $this->team($this->kanapowcy, 'buzz');

        self::assertFalse($this->buzzer->isArmed($this->room->code));
    }

    public function testHostSeesWhoHasTheFloor(): void
    {
        $this->host('arm');
        $this->team($this->dywanowi, 'buzz');

        $view = $this->engine->hostView($this->room);
        self::assertIsArray($view['buzz']);
        self::assertSame('Dywanowi', $view['buzz']['teamName']);
    }

    /**
     * Telefon musi rozroznic "to ja mam glos" od "ktos inny" - to jedyna
     * roznica miedzy ekranem "MASZ GLOS" a lockoutem (spec 9).
     */
    public function testOnlyTheBuzzingTeamGetsTheFloorOnItsPhone(): void
    {
        $this->host('arm');
        $this->team($this->kanapowcy, 'buzz');

        self::assertTrue($this->engine->playerView($this->room, $this->kanapowcy)['hasFloor']);
        self::assertFalse($this->engine->playerView($this->room, $this->dywanowi)['hasFloor']);
    }

    public function testSecondTeamIsLockedOutAndToldWhoWasFaster(): void
    {
        $this->host('arm');
        $this->team($this->kanapowcy, 'buzz');
        $mark = \count($this->published);

        $this->team($this->dywanowi, 'buzz');

        $rejected = $this->onlyUpdateSince($mark);
        self::assertTrue($rejected->isPrivate(), 'Odmowa idzie tylko do spoznionego telefonu.');
        self::assertSame(['room/'.$this->room->code.'/team/'.$this->dywanowi->id], $rejected->getTopics());

        $payload = $this->decode($rejected->getData());
        self::assertSame('buzz_rejected', $payload['type']);
        self::assertSame('locked', $payload['reason']);
        self::assertSame($this->kanapowcy->id, $payload['winner']);
    }

    public function testLockedOutBuzzDoesNotMoveTheGame(): void
    {
        $this->host('arm');
        $this->team($this->kanapowcy, 'buzz');

        $this->team($this->dywanowi, 'buzz');

        self::assertSame('buzzed', $this->engine->currentState($this->room));
        self::assertSame($this->kanapowcy->id, $this->buzzer->winner(
            $this->room->code,
            $this->buzzer->currentRound($this->room->code),
        ));
    }

    public function testWrongAnswerCostsNoPoints(): void
    {
        $this->host('arm');
        $this->team($this->kanapowcy, 'buzz');

        $this->host('judge', ['correct' => false]);

        self::assertSame(0, $this->scoreboard->get($this->room->code, $this->kanapowcy->id));
        self::assertSame('wrong', $this->engine->hostView($this->room)['lastResult']);
    }

    public function testHostCanAwardMoreThanOnePoint(): void
    {
        $this->host('arm');
        $this->team($this->kanapowcy, 'buzz');

        $this->host('judge', ['correct' => true, 'points' => 3]);

        self::assertSame(3, $this->scoreboard->get($this->room->code, $this->kanapowcy->id));
    }

    /** Najczestszy przebieg przy stole: zle zgadl, wiec utwor wraca dla reszty. */
    public function testWrongAnswerLetsTheOthersTryTheSameTrack(): void
    {
        $this->host('arm');
        $this->team($this->kanapowcy, 'buzz');
        $this->host('judge', ['correct' => false]);

        $this->host('rearm');

        self::assertSame('armed', $this->engine->currentState($this->room));
        self::assertTrue($this->buzzer->isArmed($this->room->code));

        $this->team($this->dywanowi, 'buzz');

        self::assertSame('buzzed', $this->engine->currentState($this->room));

        $buzz = $this->engine->hostView($this->room)['buzz'];
        self::assertIsArray($buzz);
        self::assertSame('Dywanowi', $buzz['teamName']);
    }

    public function testRearmOpensAFreshRoundSoTheOldOneCannotBeWonTwice(): void
    {
        $this->host('arm');
        $firstRound = $this->buzzer->currentRound($this->room->code);
        $this->team($this->kanapowcy, 'buzz');
        $this->host('judge', ['correct' => false]);
        $this->host('rearm');

        self::assertGreaterThan($firstRound, $this->buzzer->currentRound($this->room->code));
    }

    /**
     * Decyzja uzytkownika: kto sie pomyli, czeka na kolejny utwor zamiast
     * probowac w kolko, az sie uda - inaczej jedna druzyna z dobrym refleksem
     * moglaby "zgadywac na wyczucie" ten sam utwor bez konca.
     */
    public function testWrongAnswerExcludesThatTeamFromTheSameTrack(): void
    {
        $this->host('arm');
        $this->team($this->kanapowcy, 'buzz');
        $this->host('judge', ['correct' => false]);
        $this->host('rearm');

        $mark = \count($this->published);
        $this->team($this->kanapowcy, 'buzz');

        self::assertSame(
            'armed',
            $this->engine->currentState($this->room),
            'Proba wykluczonej druzyny nie moze ruszyc gry do stanu "buzzed".',
        );

        $payload = $this->decode($this->onlyUpdateSince($mark)->getData());
        self::assertSame('buzz_rejected', $payload['type']);
        self::assertSame('excluded', $payload['reason']);
    }

    /** Wykluczona druzyna dostaje jasny sygnal - inny niz zwykle "za pozno". */
    public function testExcludedTeamPlayerViewFlagsIt(): void
    {
        $this->host('arm');
        $this->team($this->kanapowcy, 'buzz');
        $this->host('judge', ['correct' => false]);
        $this->host('rearm');

        self::assertTrue($this->engine->playerView($this->room, $this->kanapowcy)['excluded']);
        self::assertFalse($this->engine->playerView($this->room, $this->dywanowi)['excluded']);
    }

    /**
     * Telefon wykluczonej druzyny ma dostac powiadomienie SAM, bez czekania,
     * az ktos wcisnie przycisk - inaczej przycisk mylnie wygladalby na
     * aktywny (event 'armed' leci do wszystkich jednakowo).
     */
    public function testExcludedTeamIsNotifiedAsSoonAsTheTrackIsRearmedWithoutPressingAnything(): void
    {
        $this->host('arm');
        $this->team($this->kanapowcy, 'buzz');
        $this->host('judge', ['correct' => false]);

        $mark = \count($this->published);
        $this->host('rearm');

        $notice = $this->privateUpdateTo($mark, $this->kanapowcy);
        $payload = $this->decode($notice->getData());
        self::assertSame('buzz_rejected', $payload['type']);
        self::assertSame('excluded', $payload['reason']);
    }

    /** Host widzi, kto juz probowal - bez zagladania w telefony drużyn. */
    public function testHostViewListsTeamsExcludedFromTheCurrentTrack(): void
    {
        $this->host('arm');
        $this->team($this->kanapowcy, 'buzz');
        $this->host('judge', ['correct' => false]);

        self::assertSame([$this->kanapowcy->id], $this->engine->hostView($this->room)['wrongTeams']);
    }

    /** Nowy utwor to czysta karta - wykluczenia z poprzedniego juz nie obowiazuja. */
    public function testNextTrackClearsExclusionsSoEveryoneCanTryAgain(): void
    {
        $this->host('arm');
        $this->team($this->kanapowcy, 'buzz');
        $this->host('judge', ['correct' => false]);
        $this->host('finish');
        $this->host('next_track');

        self::assertSame([], $this->engine->hostView($this->room)['wrongTeams']);
        self::assertFalse($this->engine->playerView($this->room, $this->kanapowcy)['excluded']);

        $this->host('arm');
        $this->team($this->kanapowcy, 'buzz');

        self::assertSame(
            'buzzed',
            $this->engine->currentState($this->room),
            'Na nowym utworze wczesniej wykluczona druzyna znow moze probowac.',
        );
    }

    /**
     * Zagadka rozwiazana - tytul idzie na ekran od razu, bez czekania
     * na zamkniecie rundy. Wczesniej host widzial pusty ekran i musial
     * powiedziec na glos, co to bylo.
     */
    public function testCorrectAnswerRevealsArtistAndTitle(): void
    {
        $this->host('arm');
        $this->team($this->kanapowcy, 'buzz');

        // Ktory z dwoch utworow padl, decyduje losowanie - porownujemy
        // z tym, co host ma w widoku, a nie ze sztywnym tytulem.
        $track = $this->engine->hostView($this->room)['track'];

        self::assertIsArray($track);

        $mark = \count($this->published);
        $this->host('judge', ['correct' => true]);

        $revealed = $this->engine->hostView($this->room)['revealed'];

        self::assertIsArray($revealed);
        self::assertSame($track['title'], $revealed['title']);
        self::assertSame($track['artist'], $revealed['artist']);

        // Telefony tez maja prawo wiedziec, co leciało.
        self::assertSame($track['title'], $this->eventSince($mark, 'track_revealed')['title']);
    }

    /**
     * Zla odpowiedz nie odslania niczego - utwor wraca dla pozostalych druzyn,
     * a odslonietego tytulu nie dalo by sie juz schowac.
     */
    public function testWrongAnswerKeepsTheTitleHidden(): void
    {
        $this->host('arm');
        $this->team($this->kanapowcy, 'buzz');
        $this->host('judge', ['correct' => false]);

        self::assertNull($this->engine->hostView($this->room)['revealed']);
    }

    /** Nikt nie zabuzzowal - bez tego pokoj zostalby uzbrojony na zawsze. */
    public function testHostCanAbortARoundNobodyAnswered(): void
    {
        $this->host('arm');

        $this->host('cancel');

        self::assertSame('idle', $this->engine->currentState($this->room));
        self::assertFalse($this->buzzer->isArmed($this->room->code));

        // ...i od razu da sie puscic kolejny utwor.
        $this->host('arm');
        self::assertSame('armed', $this->engine->currentState($this->room));
    }

    /**
     * Dwie mozliwe kolejnosci rozstrzygniecia wyscigu buzz/cancel - obie
     * musza zostawiac stan spojny, nigdy "broadcast BUZZ poszedl, a gra go
     * nie widziala". RoomLock (GameEngine::handle) serializuje wszystkie
     * akcje na tym samym pokoju, wiec te dwie sekwencje sa JEDYNYMI mozliwymi
     * wynikami rownoleglego zadania hosta ('cancel') i druzyny ('buzz') -
     * nigdy przeplotu w polowie.
     *
     * Tutaj: buzz zdazyl pierwszy (zaakceptowany, FSM w 'buzzed'). Host, ktory
     * kliknal "nikt nie zgadl" ulamek sekundy pozniej, dostaje odmowe zamiast
     * cichego przejscia do 'idle' z pominieciem buzza kogos, kto wlasnie
     * zabuzzowal.
     */
    public function testCancelAfterABuzzAlreadyWonIsRefusedInsteadOfSilentlySkippingIt(): void
    {
        $this->host('arm');
        $this->team($this->kanapowcy, 'buzz');

        self::assertSame('buzzed', $this->engine->currentState($this->room));

        $this->expectException(IllegalActionException::class);

        try {
            $this->host('cancel');
        } finally {
            // Niezaleznie od tego, czy wyjatek poleci - stan MUSI zostac
            // 'buzzed', nie przeskoczyc cicho do 'idle'.
            self::assertSame('buzzed', $this->engine->currentState($this->room));
        }
    }

    /**
     * Odwrotna kolejnosc: host zdazyl pierwszy (cancel wykonal sie w calosci,
     * buzzer zgaszony). Buzz druzyny, ktory dotarl pozniej, dostaje uprzejma
     * odmowe (tak jak "nacisniecie zanim host cokolwiek puscil") zamiast
     * rozgloszenia zwyciestwa dla rundy, ktorej juz nie ma.
     */
    public function testBuzzAfterACancelHasAlreadyHappenedIsRefused(): void
    {
        $this->host('arm');
        $this->host('cancel');

        self::assertSame('idle', $this->engine->currentState($this->room));

        $mark = \count($this->published);

        $this->team($this->kanapowcy, 'buzz');

        self::assertSame('idle', $this->engine->currentState($this->room), 'Odrzucony buzz nie moze ruszyc FSM.');

        $newEvents = \array_slice($this->published, $mark);
        self::assertNotEmpty($newEvents, 'Druzyna musi dostac odpowiedz - choc odmowna.');

        foreach ($newEvents as $update) {
            $payload = json_decode($update->getData(), true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($payload);
            self::assertNotSame('buzz', $payload['type'] ?? null, 'Spozniony buzz nie moze rozglosic zwyciestwa.');
        }
    }

    /**
     * Nacisniecie, zanim host cokolwiek pusci, nie jest bledem - ktos po prostu
     * bawi sie przyciskiem. Telefon dostaje odmowe, a gra stoi w miejscu;
     * wyjatek zamieniłby zabawe przyciskiem w blad 400 na ekranie gracza.
     */
    public function testBuzzBeforeTheHostArmsIsPolitelyRefused(): void
    {
        $mark = \count($this->published);

        $this->team($this->kanapowcy, 'buzz');

        $payload = $this->decode($this->onlyUpdateSince($mark)->getData());

        self::assertSame('buzz_rejected', $payload['type']);
        self::assertSame('stale', $payload['reason']);
        self::assertSame('idle', $this->engine->currentState($this->room));
    }

    public function testPhoneCannotArmTheRound(): void
    {
        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessageMatches('/tylko dla hosta/');

        $this->team($this->kanapowcy, 'arm');
    }

    public function testPhoneCannotJudgeItself(): void
    {
        $this->host('arm');
        $this->team($this->kanapowcy, 'buzz');

        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessageMatches('/tylko dla hosta/');

        $this->team($this->kanapowcy, 'judge', ['correct' => true]);
    }

    public function testHostCannotBuzzForATeam(): void
    {
        $this->host('arm');

        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessageMatches('/tylko dla druzyny/');

        $this->host('buzz');
    }

    /**
     * Telefon, ktory nie zauwazyl konca rundy, wysyla numer sprzed chwili.
     * Ma dostac odmowe, a nie zaliczenie do biezacej rundy.
     */
    public function testBuzzCarryingAStaleRoundNumberIsRefused(): void
    {
        $this->host('arm');
        $this->team($this->kanapowcy, 'buzz');
        $this->host('judge', ['correct' => false]);
        $this->host('rearm');
        $mark = \count($this->published);

        $this->team($this->dywanowi, 'buzz', ['round' => 1]);

        $payload = $this->decode($this->onlyUpdateSince($mark)->getData());

        self::assertSame('buzz_rejected', $payload['type']);
        self::assertSame('locked', $payload['reason']);
        self::assertSame($this->kanapowcy->id, $payload['winner'], 'Runda 1 nalezala do Kanapowcow.');
        self::assertSame('armed', $this->engine->currentState($this->room), 'Biezaca runda ma trwac dalej.');
    }

    public function testJudgingWithoutABuzzIsRefused(): void
    {
        $this->host('arm');

        $this->expectException(IllegalActionException::class);

        $this->host('judge', ['correct' => true]);
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
     * Event danego typu wsrod opublikowanych po znaczniku. Rdzen doklada
     * wlasny event zmiany fazy, wiec nasz nie jest jedyny.
     *
     * @return array<string, mixed>
     */
    private function eventSince(int $mark, string $type): array
    {
        foreach (\array_slice($this->published, $mark) as $update) {
            $payload = $this->decode($update->getData());

            if (($payload['type'] ?? null) === $type) {
                return $payload;
            }
        }

        self::fail(sprintf('Nie znalazlem eventu "%s".', $type));
    }

    private function onlyUpdateSince(int $mark): Update
    {
        $updates = \array_slice($this->published, $mark);

        self::assertCount(1, $updates, 'Oczekiwano dokladnie jednego eventu.');

        return $updates[0];
    }

    /** Prywatny update do konkretnej druzyny wsrod opublikowanych po znaczniku. */
    private function privateUpdateTo(int $mark, Team $team): Update
    {
        $topic = 'room/'.$this->room->code.'/team/'.$team->id;

        foreach (\array_slice($this->published, $mark) as $update) {
            if (\in_array($topic, $update->getTopics(), true)) {
                return $update;
            }
        }

        self::fail(sprintf('Nie znalazlem prywatnego update-u do druzyny "%s".', $team->name));
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
