<?php

declare(strict_types=1);

namespace App\Tests\Game\Dutch;

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
use App\Game\Dutch\DutchGame;
use App\Tests\RedisConnection;
use App\Tests\RedisTestCase;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

/**
 * Dutch przez silnik.
 *
 * Sedno tej gry to ukryta informacja: cudze karty i karta dobrana muszą zostać
 * tajne do końca rundy. Gdyby wyciekały broadcastem, wystarczyłoby zajrzeć
 * w narzędzia deweloperskie, żeby podejrzeć rękę sąsiada. Reszta testów pilnuje
 * tur i punktacji - jedynych miejsc, w których da się skrzywdzić gracza.
 */
final class DutchGameTest extends RedisTestCase
{
    use RedisConnection;

    private const TTL = 60;

    private GameEngine $engine;
    private GameState $gameState;
    private RoomManager $rooms;
    private Room $room;
    private Team $ania;
    private Team $bartek;

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

        $game = new DutchGame($fsm, $this->gameState, $this->rooms);
        $this->engine = new GameEngine(new GameRegistry([$game]), $this->gameState, $fsm, $this->rooms, $publisher);

        $this->room = $this->rooms->create();
        $this->ania = $this->rooms->join($this->room->code, 'Ania');
        $this->bartek = $this->rooms->join($this->room->code, 'Bartek');

        $this->engine->start($this->room, DutchGame::type());
    }

    public function testDealGivesEveryoneFourCardsAndOpensPeek(): void
    {
        $this->host('deal');

        $view = $this->engine->playerView($this->room, $this->ania);

        self::assertSame('peek', $view['state']);
        self::assertSame(4, $view['handSize']);
        self::assertSame(52 - 2 * 4, $view['deckCount']);
        self::assertSame($this->ania->id, $view['turn']);
    }

    public function testDealNeedsAtLeastTwoPlayers(): void
    {
        $solo = $this->rooms->create();
        $this->rooms->join($solo->code, 'Sam');
        $this->engine->start($solo, DutchGame::type());

        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessage('co najmniej dwóch');

        $this->engine->handle($solo, 'deal', [], Actor::host());
    }

    /** Podgląd oddaje wyłącznie własną kartę i wyłącznie temu telefonowi. */
    public function testPeekRevealsOnlyMyOwnCard(): void
    {
        $this->host('deal');
        $this->team($this->ania, 'peek', ['slot' => 0]);

        $mine = $this->engine->playerView($this->room, $this->ania)['peekedCards'];
        $theirs = $this->engine->playerView($this->room, $this->bartek)['peekedCards'];

        self::assertIsArray($mine);
        self::assertArrayHasKey(0, $mine);
        self::assertSame([], $theirs);
    }

    /**
     * Stół musi wiedzieć, kto już podejrzał karty. Fakt (bez wartości) leci
     * publicznie; sama karta zostaje prywatna dla gracza.
     */
    public function testPeekTellsTheTableWhoIsReady(): void
    {
        $this->host('deal');
        $this->team($this->ania, 'peek', ['slot' => 0]);

        // Publicznie leci tylko postęp - żadnej karty na wspólnym topiku.
        self::assertStringContainsString('peek_progress', $this->roomTopicData());
        $this->assertNoCardsOnRoomTopic();

        // Widok hosta liczy postęp z kontekstu.
        $progress = $this->engine->hostView($this->room)['peekProgress'];
        self::assertIsArray($progress);
        self::assertSame(1, $progress[$this->ania->id] ?? null);
    }

    public function testPeekIsLimitedToTwoCards(): void
    {
        $this->host('deal');
        $this->team($this->ania, 'peek', ['slot' => 0]);
        $this->team($this->ania, 'peek', ['slot' => 1]);

        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessage('tylko dwie karty');

        $this->team($this->ania, 'peek', ['slot' => 2]);
    }

    /** Uczciwa pamięć: gdy runda rusza, podejrzane karty znikają z widoku. */
    public function testPeekedCardsVanishWhenPlayBegins(): void
    {
        $this->host('deal');
        $this->team($this->ania, 'peek', ['slot' => 0]);
        $this->host('begin');

        $view = $this->engine->playerView($this->room, $this->ania);

        self::assertSame('playing', $view['state']);
        self::assertSame([], $view['peekedCards']);
    }

    public function testOnlyTheCurrentPlayerCanDraw(): void
    {
        $this->startPlaying();

        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessage('nie twoja tura');

        $this->team($this->bartek, 'draw');
    }

    /** Dobrana karta trafia tylko do dobierającego - nigdy na wspólny topic. */
    public function testDrawnCardStaysPrivate(): void
    {
        $this->startPlaying();
        $this->team($this->ania, 'draw');

        $view = $this->engine->playerView($this->room, $this->ania);
        self::assertIsArray($view['drawn']);

        // Bartek nie zna karty Ani, a host (telewizor) też nie.
        self::assertNull($this->engine->playerView($this->room, $this->bartek)['drawn']);
        self::assertNoCardsOnRoomTopic();
    }

    public function testDrawingTwiceIsRefused(): void
    {
        $this->startPlaying();
        $this->team($this->ania, 'draw');

        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessage('już dobraną');

        $this->team($this->ania, 'draw');
    }

    /** Podmiana przekazuje turę i odkłada starą kartę na stos. */
    public function testSwapAdvancesTheTurnAndDiscardsOldCard(): void
    {
        $this->startPlaying();
        $this->team($this->ania, 'draw');
        $this->team($this->ania, 'swap', ['slot' => 0]);

        $view = $this->engine->hostView($this->room);

        self::assertSame($this->bartek->id, $view['turn']);
        self::assertIsArray($view['discardTop']);
        self::assertSame(1, $view['discardCount']);
    }

    /**
     * Trafny "Dutch": wołający ma samodzielnie najniższą sumę. Reszta gra
     * jeszcze jedną turę, a potem odkrywamy karty.
     */
    public function testCorrectDutchCallEndsWithCallerWinning(): void
    {
        $this->startPlaying();
        $this->forceHands([
            $this->ania->id => [0, 1, 2, 3],       // A 2 3 4 = 10
            $this->bartek->id => [10, 11, 12, 9],  // J Q K 10 = 40
        ]);

        $this->team($this->ania, 'call');
        self::assertSame('final', $this->engine->hostView($this->room)['state']);

        // Bartek dograł swoją ostatnią turę - i runda się kończy sama.
        $this->team($this->bartek, 'draw');
        $this->team($this->bartek, 'discard');

        $scores = $this->scores();

        self::assertTrue($scores['callCorrect']);
        self::assertSame([$this->ania->id], $scores['winners']);
        self::assertSame('reveal', $this->engine->hostView($this->room)['state']);
    }

    /** Błędny "Dutch": wołający nie miał najniżej - kara doklada się do sumy. */
    public function testWrongDutchCallCostsAPenalty(): void
    {
        $this->startPlaying();
        $this->forceHands([
            $this->ania->id => [10, 11, 12, 9],    // 40 - a jednak woła
            $this->bartek->id => [0, 1, 2, 3],     // 10
        ]);

        $this->team($this->ania, 'call');
        $this->team($this->bartek, 'draw');
        $this->team($this->bartek, 'discard');

        $scores = $this->scores();
        $sums = $scores['sums'];
        self::assertIsArray($sums);

        self::assertFalse($scores['callCorrect']);
        self::assertSame([$this->bartek->id], $scores['winners']);
        self::assertSame(40 + 5, $sums[$this->ania->id] ?? null);
    }

    /** Ranking narasta między rundami, a odkryte ręce są jawne dopiero na końcu. */
    public function testRevealPublishesHandsAndAccumulatesTotals(): void
    {
        $this->startPlaying();
        $this->forceHands([
            $this->ania->id => [0, 1, 2, 3],       // 10
            $this->bartek->id => [10, 11, 12, 9],  // 40
        ]);

        // Do odkrycia kart nie doszło ani jedno rozgłoszenie z kartą.
        self::assertNoCardsOnRoomTopic();

        $this->host('finish');

        self::assertSame('reveal', $this->engine->hostView($this->room)['state']);
        self::assertIsArray($this->engine->hostView($this->room)['revealed']);

        $first = $this->totals();
        self::assertSame(10, $first[$this->ania->id] ?? null);
        self::assertSame(40, $first[$this->bartek->id] ?? null);

        // Kolejna runda dokłada do rankingu, a nie zaczyna od zera.
        $this->host('next_round');
        $this->host('deal');
        $this->forceHands([
            $this->ania->id => [12, 12, 12, 12],   // 40
            $this->bartek->id => [0, 1, 2, 3],     // 10
        ]);
        $this->host('begin');
        $this->host('finish');

        $totals = $this->totals();
        self::assertSame(50, $totals[$this->ania->id] ?? null);
        self::assertSame(50, $totals[$this->bartek->id] ?? null);
    }

    /**
     * Dama: podglądasz WŁASNĄ kartę. Odsłonięcie trafia wyłącznie do ciebie,
     * a tura po nim się kończy.
     */
    public function testQueenPeeksYourOwnCardPrivately(): void
    {
        $this->startPlaying();
        $this->forceHands([
            $this->ania->id => [0, 1, 2, 3],  // A♠ na polu 0
            $this->bartek->id => [4, 5, 6, 7],
        ]);
        $this->forceDrawn($this->ania, 11); // Q♠ (moc: podejrzyj swoją)

        $this->team($this->ania, 'power');
        self::assertSame($this->ania->id, $this->engine->hostView($this->room)['turn']);

        $this->team($this->ania, 'power_peek', ['teamId' => $this->ania->id, 'slot' => 0]);

        // Podejrzana karta poszła tylko do Ani, nigdy na wspólny topic.
        self::assertStringNotContainsString('A♠', $this->roomTopicData());
        self::assertStringContainsString('A♠', $this->teamTopicData($this->ania));
        self::assertSame($this->bartek->id, $this->engine->hostView($this->room)['turn']);
    }

    /** Damą nie wolno podejrzeć cudzej karty - tylko własną. */
    public function testQueenCannotPeekAnOpponentCard(): void
    {
        $this->startPlaying();
        $this->forceDrawn($this->ania, 11); // Q♠
        $this->team($this->ania, 'power');

        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessage('własną');

        $this->team($this->ania, 'power_peek', ['teamId' => $this->bartek->id, 'slot' => 0]);
    }

    /**
     * Walet: zamiana jednej karty między wybranymi graczami. Po niej pola mają
     * przełożone karty, a tura się kończy.
     */
    public function testJackSwapsOneCardBetweenTwoChosenPlayers(): void
    {
        $this->startPlaying();
        // Dwa rozłączne zestawy, żeby dało się wyśledzić, co się przełożyło.
        $this->forceHands([
            $this->ania->id => [0, 1, 2, 3],       // "niskie"
            $this->bartek->id => [10, 11, 12, 13], // "wysokie"
        ]);
        $this->forceDrawn($this->ania, 10); // J♠ (moc: zamiana)

        $this->team($this->ania, 'power');
        $this->team($this->ania, 'power_swap', ['a' => $this->ania->id, 'b' => $this->bartek->id]);

        $hands = $this->gameState->ctx($this->room->code)['_hands'];
        self::assertIsArray($hands);
        $aniaHand = $hands[$this->ania->id] ?? null;
        $bartekHand = $hands[$this->bartek->id] ?? null;
        self::assertIsArray($aniaHand);
        self::assertIsArray($bartekHand);

        // Losowo, ale zawsze DOKŁADNIE jedna karta przeszła w każdą stronę.
        self::assertSame(3, $this->countFrom($aniaHand, [0, 1, 2, 3]));
        self::assertSame(1, $this->countFrom($aniaHand, [10, 11, 12, 13]));
        self::assertSame(3, $this->countFrom($bartekHand, [10, 11, 12, 13]));
        self::assertSame(1, $this->countFrom($bartekHand, [0, 1, 2, 3]));

        // I tura przeszła dalej.
        self::assertSame($this->bartek->id, $this->engine->hostView($this->room)['turn']);
    }

    /** Walet musi wskazać dwóch RÓŻNYCH graczy. */
    public function testJackNeedsTwoDifferentPlayers(): void
    {
        $this->startPlaying();
        $this->forceDrawn($this->ania, 10); // J♠
        $this->team($this->ania, 'power');

        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessage('różnych graczy');

        $this->team($this->ania, 'power_swap', ['a' => $this->ania->id, 'b' => $this->ania->id]);
    }

    /**
     * Dobrana karta niesie swoją moc do telefonu. Bez tego przycisk „Podejrzyj"
     * przy dobraniu damy na żywo w ogóle się nie pokazywał.
     */
    public function testDrawnCardCarriesItsPowerToThePhone(): void
    {
        $this->startPlaying();
        $this->gameState->mergeCtx($this->room->code, ['_deck' => [11, 0, 1]]); // dama (Q♠) na wierzchu
        $this->team($this->ania, 'draw');

        self::assertStringContainsString('peek_own', $this->teamTopicData($this->ania));
        self::assertSame('peek_own', $this->engine->playerView($this->room, $this->ania)['drawnPower']);
    }

    public function testCannotDrawWhileThePowerIsPending(): void
    {
        $this->startPlaying();
        $this->forceDrawn($this->ania, 11); // Q♠
        $this->team($this->ania, 'power');

        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessage('wskaż kartę do podejrzenia');

        $this->team($this->ania, 'draw');
    }

    /** Karta bez mocy (7-10, as, król) nie ma akcji - tylko punkty. */
    public function testAPowerlessCardHasNoAction(): void
    {
        $this->startPlaying();
        $this->forceDrawn($this->ania, 8); // 9♠ - w tym wariancie bez mocy

        $this->expectException(IllegalActionException::class);
        $this->expectExceptionMessage('nie ma mocy');

        $this->team($this->ania, 'power');
    }

    /**
     * Dorzucenie pasującej karty (ta sama figura co wierzch stosu) ujmuje ją
     * z ręki - tak pozbywasz się kart. Liczy się figura, nie kolor.
     */
    public function testSnapMatchingRankRemovesTheCard(): void
    {
        $this->startPlaying();
        $this->forceHands([
            $this->ania->id => [0, 1, 2, 3],   // ...4♠ (id 3) na polu 3
            $this->bartek->id => [4, 5, 6, 7],
        ]);
        $this->forceDiscard([16]); // 4♥ na wierzchu - ta sama figura, inny kolor

        $this->team($this->ania, 'snap', ['slot' => 3]);

        $hands = $this->gameState->ctx($this->room->code)['_hands'];
        self::assertIsArray($hands);
        $aniaHand = $hands[$this->ania->id] ?? null;
        self::assertIsArray($aniaHand);
        self::assertSame(-1, $aniaHand[3] ?? null); // pole opróżnione

        // Karta trafiła na stos, a ręka na telefonie ma tam już lukę.
        $top = $this->engine->hostView($this->room)['discardTop'];
        self::assertIsArray($top);
        self::assertSame('4♠', $top['label'] ?? null);

        $myHand = $this->engine->playerView($this->room, $this->ania)['myHand'];
        self::assertIsArray($myHand);
        self::assertFalse($myHand[3] ?? null);
    }

    /** Dorzucać może KAŻDY, nie tylko gracz w swojej turze. */
    public function testAnyPlayerCanSnapNotJustTheCurrentOne(): void
    {
        $this->startPlaying();
        self::assertSame($this->ania->id, $this->engine->hostView($this->room)['turn']);
        $this->forceHands([
            $this->ania->id => [0, 1, 2, 3],
            $this->bartek->id => [16, 5, 6, 7], // 4♥ (id 16) na polu 0
        ]);
        $this->forceDiscard([3]); // 4♠ na wierzchu

        // Nie jest tura Bartka, a mimo to może dorzucić.
        $this->team($this->bartek, 'snap', ['slot' => 0]);

        $hands = $this->gameState->ctx($this->room->code)['_hands'];
        self::assertIsArray($hands);
        $bartekHand = $hands[$this->bartek->id] ?? null;
        self::assertIsArray($bartekHand);
        self::assertSame(-1, $bartekHand[0] ?? null);
        // Dorzucenie nie rusza tury.
        self::assertSame($this->ania->id, $this->engine->hostView($this->room)['turn']);
    }

    /** Nietrafione dorzucenie zostawia kartę i dokłada karną - ręka rośnie. */
    public function testWrongSnapDrawsAPenaltyCard(): void
    {
        $this->startPlaying();
        $this->forceHands([
            $this->ania->id => [0, 1, 2, 3],
            $this->bartek->id => [4, 5, 6, 7],
        ]);
        $this->forceDiscard([12]); // K♠ (figura 13) - żadna karta Ani nie pasuje

        $this->team($this->ania, 'snap', ['slot' => 0]); // A♠ - pudło

        $hands = $this->gameState->ctx($this->room->code)['_hands'];
        self::assertIsArray($hands);
        $aniaHand = $hands[$this->ania->id] ?? null;
        self::assertIsArray($aniaHand);
        self::assertCount(5, $aniaHand);          // 4 + karna
        self::assertSame(0, $aniaHand[0] ?? null); // źle wskazana karta została

        // Kara pamięciowa: wszyscy zobaczyli, co chciał wyrzucić (A♠) - jawnie.
        self::assertStringContainsString('A♠', $this->roomTopicData());
    }

    private function startPlaying(): void
    {
        $this->host('deal');
        $this->host('begin');
    }

    private function forceDrawn(Team $team, int $cardId): void
    {
        $this->gameState->mergeCtx($this->room->code, ['_drawn' => $cardId, '_drawnBy' => $team->id]);
    }

    /** @param list<int> $cards */
    private function forceDiscard(array $cards): void
    {
        $this->gameState->mergeCtx($this->room->code, ['_discard' => $cards]);
    }

    /**
     * Ile kart z podanego zestawu jest w ręce - do śledzenia losowej zamiany.
     *
     * @param array<mixed> $hand
     * @param list<int>    $ids
     */
    private function countFrom(array $hand, array $ids): int
    {
        $count = 0;

        foreach ($hand as $card) {
            if (\is_int($card) && \in_array($card, $ids, true)) {
                ++$count;
            }
        }

        return $count;
    }

    /** Zlepek danych ze wszystkich update'ów na jawnym topiku pokoju. */
    private function roomTopicData(): string
    {
        return $this->topicData(sprintf('room/%s', $this->room->code));
    }

    /** Zlepek danych z prywatnego topiku jednej drużyny. */
    private function teamTopicData(Team $team): string
    {
        return $this->topicData(sprintf('room/%s/team/%s', $this->room->code, $team->id));
    }

    private function topicData(string $topic): string
    {
        $out = '';

        foreach ($this->published as $update) {
            if (\in_array($topic, $update->getTopics(), true)) {
                $out .= $update->getData();
            }
        }

        return $out;
    }

    /** @param array<string, list<int>> $hands */
    private function forceHands(array $hands): void
    {
        $this->gameState->mergeCtx($this->room->code, ['_hands' => $hands]);
    }

    /** @return array<string, mixed> wynik rundy z widoku hosta */
    private function scores(): array
    {
        $scores = $this->engine->hostView($this->room)['scores'];

        self::assertIsArray($scores);

        $out = [];
        foreach ($scores as $key => $value) {
            if (\is_string($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** @return array<string, int> narastający ranking z widoku hosta */
    private function totals(): array
    {
        $totals = $this->engine->hostView($this->room)['totals'];

        self::assertIsArray($totals);

        $out = [];
        foreach ($totals as $teamId => $value) {
            if (\is_string($teamId) && \is_int($value)) {
                $out[$teamId] = $value;
            }
        }

        return $out;
    }

    /**
     * Zaden update na topiku pokoju (jawny dla wszystkich) nie moze niesc karty.
     * Karty jada wylacznie prywatnie - do jednej druzyny. Symbol koloru w danych
     * jawnego update'u znaczylby, ze rozwiazanie wisi na wspolnym topiku.
     */
    private function assertNoCardsOnRoomTopic(): void
    {
        $roomTopic = sprintf('room/%s', $this->room->code);

        foreach ($this->published as $update) {
            if (!\in_array($roomTopic, $update->getTopics(), true)) {
                continue;
            }

            foreach (['♠', '♥', '♦', '♣'] as $suit) {
                self::assertStringNotContainsString(
                    $suit,
                    $update->getData(),
                    'Karta wyciekła na wspólny topic pokoju.',
                );
            }
        }
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
