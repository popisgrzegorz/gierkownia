<?php

declare(strict_types=1);

namespace App\Game\KalamburyRysowane;

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
use App\Entity\Phrase;

/**
 * Kalambury rysowane: jedna osoba z drużyny rysuje na tablecie, reszta stołu
 * zgaduje na głos - host klika, kto trafił. Poza planem §13, siostrzana gra
 * zwykłych Kalambur (ta sama talia haseł, ten sam duch "host widzi hasło,
 * telefony nie"), ale dwie rzeczy są inne.
 *
 * **Trudność wybiera rysujący, nie los.** `deal()` oferuje trzy nieużyte hasła
 * naraz - po jednym z każdego poziomu (Phrase::DIFFICULTIES) - i to rysujący
 * decyduje, ile punktów ma być do zdobycia. Wszystkie trzy trafiają jako
 * `_played`, więc odrzucona oferta nie wraca w kolejnej turze (ten sam duch co
 * `skip` w zwykłych Kalamburach).
 *
 * **Punkty topnieją z czasem.** Pierwsze 10 sekund (GRACE_SECONDS) to pełna
 * pula z wybranego poziomu; potem topnieje liniowo do 1 wraz z upływającym
 * czasem - `pointsFor()` liczy to z tego samego `GameTimer`, z którego telewizor
 * i tablet czerpią wyświetlany licznik, więc pokazywana liczba i naliczony
 * wynik nigdy się nie rozjadą.
 *
 * Rysunek sam w sobie NIE jest stanem tej klasy - leci osobnym, lekkim kanałem
 * (GameController::live(), z pominięciem silnika i blokady pokoju), bo to czysta
 * dekoracja jak animacje GSAP w Dutchu, nie coś, o czym rozstrzyga serwer.
 */
final class KalamburyRysowaneGame extends AbstractGame
{
    private const MIN_SECONDS = 30;
    private const MAX_SECONDS = 240;
    private const DEFAULT_SECONDS = 90;

    /** Sekundy pełnej puli punktów, zanim zacznie topnieć (spec ustalona z użytkownikiem). */
    private const GRACE_SECONDS = 10;

    /** @var array<string, int> poziom trudności -> pula punktów w pełni czasu */
    private const BASE_POINTS = ['latwy' => 3, 'sredni' => 5, 'trudny' => 8];

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
        return 'kalambury-rysowane';
    }

    public function name(): string
    {
        return 'Kalambury rysowane';
    }

    public function definition(): GameDefinition
    {
        return new GameDefinition(
            type: self::type(),
            initialState: 'idle',
            states: ['idle', 'dealt', 'drawing', 'guessed', 'timeout'],
            transitions: [
                new Transition('deal', 'idle', 'dealt'),
                new Transition('choose', 'dealt', 'drawing'),
                // Rysujący nie musi brać żadnej z trzech ofert - jak 'skip'
                // w zwykłych Kalamburach, wraca do 'idle' i host prosi o nowe.
                new Transition('skip', 'dealt', 'idle'),
                new Transition('guessed', ['drawing', 'timeout'], 'guessed'),
                new Transition('timeout', 'drawing', 'timeout'),
                new Transition('next_round', ['guessed', 'timeout'], 'idle'),
            ],
        );
    }

    /** @param array<string, mixed> $payload */
    public function handleAction(Room $room, string $action, array $payload, Actor $actor): array
    {
        return match ($action) {
            'pick_category' => $this->pickCategory($room, $payload, $actor),
            'deal' => $this->deal($room, $actor),
            'choose' => $this->choose($room, $payload, $actor),
            'skip' => $this->skip($room, $actor),
            'guessed' => $this->guessed($room, $payload, $actor),
            'timeout' => $this->timeout($room, $actor),
            'next_round' => $this->nextRound($room, $actor),
            default => throw new IllegalActionException(sprintf('Kalambury rysowane nie znają akcji "%s".', $action)),
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
            throw new IllegalActionException('Nie ma takiej kategorii.');
        }

        $seconds = \is_int($payload['seconds'] ?? null) ? $payload['seconds'] : self::DEFAULT_SECONDS;

        if ($seconds < self::MIN_SECONDS || $seconds > self::MAX_SECONDS) {
            throw new IllegalActionException(sprintf(
                'Czas rundy musi mieścić się między %d a %d sekundami.',
                self::MIN_SECONDS,
                self::MAX_SECONDS,
            ));
        }

        $this->turns->start($room->code, $this->rooms->teams($room->code));

        $this->patchCtx($room, [
            'category' => $slug,
            'categoryName' => $this->deck->categoryName(self::type(), $slug),
            'cardTotal' => $this->deck->countPhrases(self::type(), $slug),
            'cardNumber' => 0,
            'seconds' => $seconds,
            '_played' => [],
            '_word' => null,
            '_choices' => null,
            'difficulty' => null,
            'basePoints' => null,
        ]);

        return [Message::broadcast(new Event('category_picked', [
            'category' => $slug,
            'categoryName' => $this->deck->categoryName(self::type(), $slug),
            'turn' => $this->turns->current($room->code),
        ]))];
    }

    /** @return list<Message> */
    private function deal(Room $room, Actor $actor): array
    {
        $actor->requireHost();

        $ctx = $this->ctx($room);
        $slug = \is_string($ctx['category'] ?? null) ? $ctx['category'] : '';

        if ('' === $slug) {
            throw new IllegalActionException('Najpierw wybierz kategorię.');
        }

        /** @var list<int> $played */
        $played = \is_array($ctx['_played'] ?? null) ? array_values(array_filter($ctx['_played'], '\is_int')) : [];
        $choices = $this->deck->drawChoices(self::type(), $slug, $played);

        if ([] === $choices) {
            throw new IllegalActionException('Hasła w tej kategorii się skończyły.');
        }

        $this->transition($room, 'deal');

        // Wszystkie trzy oferty od razu na "zagrane" - odrzucona oferta
        // (skip) nie ma wrocic w kolejnej turze, tak samo jak w Kalamburach.
        foreach ($choices as $phrase) {
            $played[] = (int) $phrase->getId();
        }

        $this->patchCtx($room, [
            '_choices' => array_map(
                static fn (Phrase $phrase): array => ['id' => $phrase->getId(), 'phrase' => $phrase->getPhrase()],
                $choices,
            ),
            '_played' => $played,
            'cardNumber' => \count($played),
        ]);

        return [Message::broadcast(new Event('choices_ready', [
            'cardNumber' => \count($played),
            'cardTotal' => $ctx['cardTotal'] ?? 0,
            'turn' => $this->turns->current($room->code),
        ]))];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<Message>
     */
    private function choose(Room $room, array $payload, Actor $actor): array
    {
        $actor->requireHost();

        $level = \is_string($payload['level'] ?? null) ? $payload['level'] : '';
        $ctx = $this->ctx($room);
        $choices = \is_array($ctx['_choices'] ?? null) ? $ctx['_choices'] : [];
        $choice = $choices[$level] ?? null;

        if (!\is_array($choice) || !\is_string($choice['phrase'] ?? null)) {
            throw new IllegalActionException('Wybierz jedną z zaproponowanych trudności.');
        }

        $this->transition($room, 'choose');

        $seconds = \is_int($ctx['seconds'] ?? null) ? $ctx['seconds'] : self::DEFAULT_SECONDS;
        $basePoints = self::BASE_POINTS[$level] ?? self::BASE_POINTS['sredni'];

        $this->patchCtx($room, [
            '_word' => $choice['phrase'],
            '_choices' => null,
            'difficulty' => $level,
            'basePoints' => $basePoints,
        ]);

        $started = $this->timer->start($room->code, $seconds);
        $actorTeam = $this->turns->current($room->code);

        return [
            Message::broadcast(new Event('drawing_started', [
                'difficulty' => $level,
                'basePoints' => $basePoints,
                'turn' => $actorTeam,
            ])),
            Message::broadcast(new Event(Event::TIMER, $started)),
            // Rysunek to inna runda, inne hasło - płótno wraca puste, zanim
            // ktokolwiek zdąży postawić pierwszą kreskę.
            Message::broadcast(new Event('clear_canvas', [])),
        ];
    }

    /** @return list<Message> */
    private function skip(Room $room, Actor $actor): array
    {
        $actor->requireHost();
        $this->transition($room, 'skip');
        $this->patchCtx($room, ['_choices' => null]);

        return [Message::broadcast(new Event('choices_skipped', []))];
    }

    /**
     * Zgadnięte. Punkt (ten sam, zależny od trudności i czasu) dostaje
     * zgadująca drużyna i rysująca - inaczej opłacałoby się rysować słabo,
     * żeby nikt nie zdobył punktu (ten sam powód co w zwykłych Kalamburach).
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
            throw new IllegalActionException('Wskaż drużynę, która odgadła.');
        }

        // Punkty liczymy PRZED wyczyszczeniem timera - remaining() potem
        // zwróciłoby zero i każde zgadnięcie warte byłoby jeden punkt.
        $ctx = $this->ctx($room);
        $seconds = \is_int($ctx['seconds'] ?? null) ? $ctx['seconds'] : self::DEFAULT_SECONDS;
        $basePoints = \is_int($ctx['basePoints'] ?? null) ? $ctx['basePoints'] : self::BASE_POINTS['sredni'];
        $remainingSeconds = (int) ceil($this->timer->remaining($room->code) / 1000);
        $points = self::pointsFor($basePoints, $seconds, $remainingSeconds);

        $this->transition($room, 'guessed');
        $this->timer->clear($room->code);

        $word = $ctx['_word'] ?? null;
        $actorTeam = $this->turns->current($room->code);

        $guesserTotal = $this->scoreboard->add($room->code, $team->id, $points);
        $messages = [Message::broadcast(new Event(Event::SCORE, ['teamId' => $team->id, 'total' => $guesserTotal]))];

        if (\is_string($actorTeam) && $actorTeam !== $team->id) {
            $actorTotal = $this->scoreboard->add($room->code, $actorTeam, $points);
            $messages[] = Message::broadcast(new Event(Event::SCORE, ['teamId' => $actorTeam, 'total' => $actorTotal]));
        }

        $messages[] = Message::broadcast(new Event('round_over', [
            'reason' => 'guessed',
            'teamId' => $team->id,
            'teamName' => $team->name,
            'word' => \is_string($word) ? $word : null,
            'points' => $points,
        ]));

        return $messages;
    }

    /** @return list<Message> */
    private function timeout(Room $room, Actor $actor): array
    {
        $actor->requireHost();

        if (!$this->timer->expired($room->code)) {
            throw new IllegalActionException('Czas jeszcze nie minął.');
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
    private function nextRound(Room $room, Actor $actor): array
    {
        $actor->requireHost();
        $this->transition($room, 'next_round');

        $next = $this->turns->next($room->code);
        $this->patchCtx($room, ['_word' => null, 'difficulty' => null, 'basePoints' => null]);

        return [Message::broadcast(new Event('turn', ['teamId' => $next]))];
    }

    /**
     * Punkty do zdobycia w danej chwili rundy.
     *
     * Pierwsze GRACE_SECONDS sekund to pełna pula wybranego poziomu; potem
     * topnieje liniowo wraz z pozostałym czasem, nigdy poniżej 1 - zgadnięcie
     * w ostatniej sekundzie ma się wciąż opłacać, żeby nikt nie machnął ręką
     * na dogorywającą rundę.
     */
    public static function pointsFor(int $basePoints, int $secondsTotal, int $remainingSeconds): int
    {
        $decayWindow = $secondsTotal - self::GRACE_SECONDS;

        if ($decayWindow <= 0 || $remainingSeconds >= $decayWindow) {
            return $basePoints;
        }

        $fraction = max(0, $remainingSeconds) / $decayWindow;

        return max(1, (int) round($basePoints * $fraction));
    }

    /** @return array<string, mixed> */
    public function hostView(Room $room): array
    {
        $ctx = $this->ctx($room);
        $choicesRaw = \is_array($ctx['_choices'] ?? null) ? $ctx['_choices'] : [];

        /** @var array<string, array{phrase: string}> $choices */
        $choices = [];
        foreach ($choicesRaw as $level => $choice) {
            if (\is_string($level) && \is_array($choice) && \is_string($choice['phrase'] ?? null)) {
                $choices[$level] = ['phrase' => $choice['phrase']];
            }
        }

        // Host (projektor I tablet - oba ekrany logują się tym samym tokenem
        // hosta) widzi treść ofert i wybrane hasło: musi ocenić trafność
        // zgadywania. Szablon projektora chowa je za rolą "tablet" - patrz
        // host.html.twig i CLAUDE.md, decyzja "tablet jako drugi ekran hosta".
        return $this->publicView($room) + [
            'word' => $ctx['_word'] ?? null,
            'choices' => $choices,
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
        return $this->publicView($room) + [
            'isMyTeamDrawing' => $this->turns->isCurrent($room->code, $team->id),
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
            'difficulty' => $ctx['difficulty'] ?? null,
            'basePoints' => $ctx['basePoints'] ?? null,
            'graceSeconds' => self::GRACE_SECONDS,
            'seconds' => $ctx['seconds'] ?? self::DEFAULT_SECONDS,
            'turn' => $this->turns->current($room->code),
            'deadline' => $this->timer->deadline($room->code),
            'serverNow' => (int) (microtime(true) * 1000),
        ];
    }
}
