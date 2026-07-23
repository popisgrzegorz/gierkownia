<?php

declare(strict_types=1);

namespace App\Game\Kalambury;

use App\Content\PhraseDeck;
use App\Core\Game\AbstractGame;
use App\Core\Game\Actor;
use App\Core\Game\Fsm\GameFsm;
use App\Core\Game\GameDefinition;
use App\Core\Game\GameState;
use App\Core\Game\IllegalActionException;
use App\Core\Game\Transition;
use App\Core\Realtime\Event;
use App\Core\Realtime\Message;
use App\Core\Room\Room;
use App\Core\Room\RoomManager;
use App\Core\Room\Team;
use App\Core\Score\Scoreboard;
use App\Core\Timer\GameTimer;
use App\Core\Turn\TurnManager;
use App\Game\Kolo\PuzzleBoard;

/**
 * Kalambury (spec 13.6): jedna druzyna pokazuje, reszta zgaduje, z timerem.
 *
 * Dwie rzeczy odrozniaja te gre od pozostalych.
 *
 * Haslo jest **tajne inaczej niz w kole**: tam nikt go nie zna, tu zna je
 * dokladnie jeden telefon - aktora. Idzie do niego pod-topikiem druzyny
 * (spec 6), nigdy rozgloszeniem, i nigdy nie trafia do publicznego kontekstu.
 * Dlatego siedzi pod kluczem z podkreśleniem, ktorego rdzen nie wypuszcza.
 *
 * Na telewizorze widac **ksztalt hasla** - ile wyrazow i ile znakow - tak jak
 * plansza w kole fortuny, tylko bez odslaniania liter. Zgadujacy widza, czego
 * szukaja, a i tak musza to odgadnac z pokazywania.
 */
final class KalamburyGame extends AbstractGame
{
    /** Domyslny czas rundy w sekundach; host moze podac wlasny przy starcie. */
    private const DEFAULT_SECONDS = 90;

    private const MIN_SECONDS = 15;
    private const MAX_SECONDS = 300;

    public function __construct(
        GameFsm $fsm,
        GameState $gameState,
        private readonly PhraseDeck $deck,
        private readonly TurnManager $turns,
        private readonly Scoreboard $scoreboard,
        private readonly GameTimer $timer,
        private readonly RoomManager $rooms,
    ) {
        parent::__construct($fsm, $gameState);
    }

    public static function type(): string
    {
        return 'kalambury';
    }

    public function name(): string
    {
        return 'Kalambury';
    }

    public function definition(): GameDefinition
    {
        return new GameDefinition(
            type: self::type(),
            initialState: 'idle',
            states: ['idle', 'drawn', 'acting', 'guessed', 'timeout'],
            transitions: [
                new Transition('draw', 'idle', 'drawn'),
                new Transition('act', 'drawn', 'acting'),
                new Transition('guessed', 'acting', 'guessed'),
                new Transition('timeout', 'acting', 'timeout'),
                // Rezygnacja z karty przed startem odliczania - aktor moze
                // trafic na haslo, ktorego nie zna. Bez tego utykamy w 'drawn'.
                new Transition('skip', 'drawn', 'idle'),
                new Transition('next', ['guessed', 'timeout'], 'idle'),
            ],
        );
    }

    /** @param array<string, mixed> $payload */
    public function handleAction(Room $room, string $action, array $payload, Actor $actor): array
    {
        return match ($action) {
            'pick_category' => $this->pickCategory($room, $payload, $actor),
            'next_card' => $this->nextCard($room, $actor),
            'start' => $this->start($room, $payload, $actor),
            'guessed' => $this->guessed($room, $payload, $actor),
            'timeout' => $this->timeout($room, $actor),
            'skip' => $this->skip($room, $actor),
            'next_round' => $this->nextRound($room, $actor),
            default => throw new IllegalActionException(sprintf('Kalambury nie znaja akcji "%s".', $action)),
        };
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<Message>
     */
    private function pickCategory(Room $room, array $payload, Actor $actor): array
    {
        $actor->requireHost();

        $slug = \is_string($payload['category'] ?? null) ? $payload['category'] : '';

        if ('' === $slug || null === $this->deck->categoryName(self::type(), $slug)) {
            throw new IllegalActionException('Nie ma takiej kategorii kalambur.');
        }

        $this->turns->start($room->code, $this->rooms->teams($room->code));

        $this->patchCtx($room, [
            'category' => $slug,
            'categoryName' => $this->deck->categoryName(self::type(), $slug),
            'cardTotal' => $this->deck->countPhrases(self::type(), $slug),
            'cardNumber' => 0,
            '_played' => [],
            '_word' => null,
            'mask' => null,
            'seconds' => self::DEFAULT_SECONDS,
        ]);

        return [Message::broadcast(new Event('category_picked', [
            'category' => $slug,
            'categoryName' => $this->deck->categoryName(self::type(), $slug),
            'turn' => $this->turns->current($room->code),
        ]))];
    }

    /** @return list<Message> */
    private function nextCard(Room $room, Actor $actor): array
    {
        $actor->requireHost();

        $ctx = $this->ctx($room);
        $slug = \is_string($ctx['category'] ?? null) ? $ctx['category'] : '';

        if ('' === $slug) {
            throw new IllegalActionException('Najpierw wybierz kategorie.');
        }

        /** @var list<int> $played */
        $played = \is_array($ctx['_played'] ?? null) ? array_values(array_filter($ctx['_played'], '\is_int')) : [];
        $card = $this->deck->drawNext(self::type(), $slug, $played);

        if (null === $card) {
            throw new IllegalActionException('Karty w tej kategorii sie skonczyly.');
        }

        $this->transition($room, 'draw');

        $word = $card->getPhrase();
        $played[] = (int) $card->getId();

        // Maska bez odslonietych liter: telewizor pokazuje ksztalt hasla,
        // a nie jego tresc. Ta sama funkcja co plansza kola fortuny.
        $mask = PuzzleBoard::mask($word, []);

        $this->patchCtx($room, [
            '_word' => $word,
            '_played' => $played,
            'mask' => $mask,
            'difficulty' => $card->getDifficulty(),
            'cardNumber' => \count($played),
        ]);

        $actorTeam = $this->turns->current($room->code);

        $messages = [Message::broadcast(new Event('card_drawn', [
            'mask' => $mask,
            'difficulty' => $card->getDifficulty(),
            'cardNumber' => \count($played),
            'cardTotal' => $ctx['cardTotal'] ?? 0,
            'turn' => $actorTeam,
        ]))];

        // Slowo wylacznie do aktora - pod-topic jego druzyny (spec 6).
        if (\is_string($actorTeam)) {
            $messages[] = Message::toTeam($actorTeam, new Event('your_word', ['word' => $word]));
        }

        return $messages;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<Message>
     */
    private function start(Room $room, array $payload, Actor $actor): array
    {
        $actor->requireHost();

        $seconds = \is_int($payload['seconds'] ?? null) ? $payload['seconds'] : self::DEFAULT_SECONDS;

        if ($seconds < self::MIN_SECONDS || $seconds > self::MAX_SECONDS) {
            throw new IllegalActionException(sprintf(
                'Czas rundy musi miescic sie miedzy %d a %d sekundami.',
                self::MIN_SECONDS,
                self::MAX_SECONDS,
            ));
        }

        $this->transition($room, 'act');
        $started = $this->timer->start($room->code, $seconds);
        $this->patchCtx($room, ['seconds' => $seconds]);

        // deadline razem z serverNow - telefon liczy offset wzgledem zegara
        // serwera, bo swojemu nie ma powodu ufac (patrz GameTimer).
        return [Message::broadcast(new Event(Event::TIMER, $started))];
    }

    /**
     * Zgadniete. Punkt dostaje druzyna zgadujaca i druzyna pokazujaca -
     * inaczej oplacaloby sie pokazywac slabo, zeby nikt nie zdobyl punktu.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<Message>
     */
    private function guessed(Room $room, array $payload, Actor $actor): array
    {
        $actor->requireHost();

        $teamId = \is_string($payload['teamId'] ?? null) ? $payload['teamId'] : '';
        $team = '' === $teamId ? null : $this->rooms->team($room->code, $teamId);

        if (null === $team) {
            throw new IllegalActionException('Wskaz druzyne, ktora odgadla.');
        }

        $this->transition($room, 'guessed');
        $this->timer->clear($room->code);

        $word = $this->ctx($room)['_word'] ?? null;
        $actorTeam = $this->turns->current($room->code);

        $guesserTotal = $this->scoreboard->add($room->code, $team->id, 1);
        $messages = [Message::broadcast(new Event(Event::SCORE, ['teamId' => $team->id, 'total' => $guesserTotal]))];

        if (\is_string($actorTeam) && $actorTeam !== $team->id) {
            $actorTotal = $this->scoreboard->add($room->code, $actorTeam, 1);
            $messages[] = Message::broadcast(new Event(Event::SCORE, ['teamId' => $actorTeam, 'total' => $actorTotal]));
        }

        $messages[] = Message::broadcast(new Event('round_over', [
            'reason' => 'guessed',
            'teamId' => $team->id,
            'teamName' => $team->name,
            'word' => \is_string($word) ? $word : null,
        ]));

        return $messages;
    }

    /** @return list<Message> */
    private function timeout(Room $room, Actor $actor): array
    {
        $actor->requireHost();

        // O uplywie czasu rozstrzyga serwer, nie zegar telewizora.
        if (!$this->timer->expired($room->code)) {
            throw new IllegalActionException('Czas jeszcze nie minal.');
        }

        $this->transition($room, 'timeout');
        $this->timer->clear($room->code);

        $word = $this->ctx($room)['_word'] ?? null;

        return [Message::broadcast(new Event('round_over', [
            'reason' => 'timeout',
            'word' => \is_string($word) ? $word : null,
        ]))];
    }

    /** @return list<Message> */
    private function skip(Room $room, Actor $actor): array
    {
        $actor->requireHost();
        $this->transition($room, 'skip');
        $this->patchCtx($room, ['_word' => null, 'mask' => null]);

        return [Message::broadcast(new Event('card_skipped', []))];
    }

    /** @return list<Message> */
    private function nextRound(Room $room, Actor $actor): array
    {
        $actor->requireHost();
        $this->transition($room, 'next');

        $next = $this->turns->next($room->code);
        $this->patchCtx($room, ['_word' => null, 'mask' => null]);

        return [Message::broadcast(new Event('turn', ['teamId' => $next]))];
    }

    /** @return array<string, mixed> */
    public function hostView(Room $room): array
    {
        $ctx = $this->ctx($room);

        // Host widzi haslo: musi ocenic, czy padla wlasciwa odpowiedz.
        // Widok hosta nie trafia na telefony (spec 7), ale trafia na telewizor -
        // dlatego szablon pokazuje je dopiero po zakonczeniu rundy.
        return $this->publicView($room) + [
            'word' => $ctx['_word'] ?? null,
            'categories' => $this->deck->categoriesFor(self::type()),
            'teams' => array_map(
                static fn (Team $team): array => ['id' => $team->id, 'name' => $team->name],
                $this->rooms->teams($room->code),
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function playerView(Room $room, Team $team): array
    {
        $ctx = $this->ctx($room);
        $isActor = $this->turns->isCurrent($room->code, $team->id);

        return $this->publicView($room) + [
            'iAmActor' => $isActor,
            // Slowo tylko dla aktora - reszta dostaje null, nie brak klucza,
            // zeby widok telefonu nie musial zgadywac, czy pytal wlasciwie.
            'word' => $isActor && \is_string($ctx['_word'] ?? null) ? $ctx['_word'] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function publicView(Room $room): array
    {
        $ctx = $this->ctx($room);

        return [
            'state' => $this->state($room),
            'category' => $ctx['category'] ?? null,
            'categoryName' => $ctx['categoryName'] ?? null,
            'cardNumber' => $ctx['cardNumber'] ?? 0,
            'cardTotal' => $ctx['cardTotal'] ?? 0,
            'mask' => $ctx['mask'] ?? null,
            'difficulty' => $ctx['difficulty'] ?? null,
            'seconds' => $ctx['seconds'] ?? self::DEFAULT_SECONDS,
            'turn' => $this->turns->current($room->code),
            'deadline' => $this->timer->deadline($room->code),
            'serverNow' => (int) (microtime(true) * 1000),
        ];
    }
}
