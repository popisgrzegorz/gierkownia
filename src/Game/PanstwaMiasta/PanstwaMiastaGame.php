<?php

declare(strict_types=1);

namespace App\Game\PanstwaMiasta;

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

/**
 * Panstwa i miasta (spec 13, ostatnia gra z planu).
 *
 * Host losuje litere, druzyny wypelniaja rubryki na telefonach. Pierwsza
 * druzyna, ktora skonczy, naciska STOP - i od tej chwili reszta ma ustalony
 * czas na dokonczenie. Po nim nie da sie dopisac ani slowa, bo o uplywie
 * decyduje serwer, a nie zegar telefonu (patrz GameTimer).
 *
 * Odpowiedzi sa **tajne do konca rundy**: siedza w kontekscie pod kluczem
 * z podkresleniem, ktorego rdzen nie wypuszcza w rozgloszeniu, a telefon
 * dostaje wylacznie swoje. Bez tego wystarczyloby zajrzec w narzedzia
 * deweloperskie, zeby przepisac odpowiedzi sasiada.
 *
 * Punktacja liczy sie sama (Punktacja), ale ostatnie slowo ma czlowiek:
 * kazda odpowiedz na telewizorze da sie uznac albo odrzucic jednym
 * kliknieciem, a wynik przelicza sie od razu. Serwer nie zna slownika
 * jezyka polskiego i nie bedzie udawal, ze zna.
 */
final class PanstwaMiastaGame extends AbstractGame
{
    /** Ile czasu na dokonczenie po tym, jak ktos nacisnie STOP. */
    private const DEFAULT_SECONDS = 30;

    private const MIN_SECONDS = 5;
    private const MAX_SECONDS = 180;

    /** Dluzsza odpowiedz to juz nie odpowiedz, tylko wypracowanie. */
    private const MAX_ANSWER = 60;

    public function __construct(
        GameFsm $fsm,
        GameState $gameState,
        private readonly RoomManager $rooms,
        private readonly Scoreboard $scoreboard,
        private readonly GameTimer $timer,
    ) {
        parent::__construct($fsm, $gameState);
    }

    public static function type(): string
    {
        return 'panstwa-miasta';
    }

    public function name(): string
    {
        return 'Państwa i miasta';
    }

    public function definition(): GameDefinition
    {
        return new GameDefinition(
            type: self::type(),
            initialState: 'idle',
            states: ['idle', 'writing', 'ending', 'scoring'],
            transitions: [
                new Transition('draw', 'idle', 'writing'),
                new Transition('stop', 'writing', 'ending'),
                // Koniec pisania: albo po odliczeniu, albo recznie przez hosta,
                // gdy wszyscy juz skonczyli i nikt nie chce czekac.
                new Transition('finish', ['writing', 'ending'], 'scoring'),
                new Transition('next_round', 'scoring', 'idle'),
            ],
        );
    }

    public function handleAction(Room $room, string $action, array $payload, Actor $actor): array
    {
        return match ($action) {
            'configure' => $this->configure($room, $payload, $actor),
            'draw' => $this->draw($room, $actor),
            'answer' => $this->answer($room, $payload, $actor),
            'stop' => $this->stop($room, $actor),
            'finish' => $this->finish($room, $actor),
            'toggle' => $this->toggle($room, $payload, $actor),
            'award' => $this->award($room, $actor),
            'next_round' => $this->nextRound($room, $actor),
            default => throw new IllegalActionException(sprintf('Państwa i miasta nie znają akcji "%s".', $action)),
        };
    }

    /**
     * Ustawia zestaw rubryk i czas po STOP-ie. Wolno tylko miedzy rundami -
     * podmiana kolumn w trakcie pisania zgubilaby juz wpisane odpowiedzi.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<Message>
     */
    private function configure(Room $room, array $payload, Actor $actor): array
    {
        $actor->requireHost();

        if ('idle' !== $this->state($room)) {
            throw new IllegalActionException('Ustawienia zmieniamy między rundami.');
        }

        $preset = \is_string($payload['preset'] ?? null) ? $payload['preset'] : Kolumny::DEFAULT_PRESET;

        if (!Kolumny::exists($preset)) {
            throw new IllegalActionException('Nie ma takiego zestawu rubryk.');
        }

        $seconds = \is_int($payload['seconds'] ?? null) ? $payload['seconds'] : $this->seconds($room);

        if ($seconds < self::MIN_SECONDS || $seconds > self::MAX_SECONDS) {
            throw new IllegalActionException(sprintf(
                'Czas po STOP-ie musi mieścić się między %d a %d sekundami.',
                self::MIN_SECONDS,
                self::MAX_SECONDS,
            ));
        }

        $this->patchCtx($room, [
            'preset' => $preset,
            'columns' => Kolumny::columns($preset),
            'seconds' => $seconds,
        ]);

        return [Message::broadcast(new Event('configured', [
            'preset' => $preset,
            'columns' => Kolumny::columns($preset),
            'seconds' => $seconds,
        ]))];
    }

    /** @return list<Message> */
    private function draw(Room $room, Actor $actor): array
    {
        $actor->requireHost();

        $ctx = $this->ctx($room);
        $used = $this->stringList($ctx['_used'] ?? null);
        $letter = Kolumny::drawLetter($used);
        $used[] = $letter;

        $this->transition($room, 'draw');

        $columns = $this->columns($room);
        $round = \is_int($ctx['round'] ?? null) ? $ctx['round'] + 1 : 1;

        $this->patchCtx($room, [
            'letter' => $letter,
            'columns' => $columns,
            'round' => $round,
            '_used' => $used,
            // Nowa runda to czysta kartka - inaczej poprzednie odpowiedzi
            // policzylyby sie drugi raz.
            '_answers' => [],
            '_overrides' => [],
            '_awarded' => false,
            'stoppedBy' => null,
            'results' => null,
        ]);

        return [Message::broadcast(new Event('letter_drawn', [
            'letter' => $letter,
            'columns' => $columns,
            'round' => $round,
            'seconds' => $this->seconds($room),
        ]))];
    }

    /**
     * Zapisuje jedna rubryke. Telefon wysyla ja przy kazdej zmianie, bo koniec
     * rundy jest twardy - odpowiedz nadana sekunde po czasie juz nie wejdzie.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<Message>
     */
    private function answer(Room $room, array $payload, Actor $actor): array
    {
        $team = $actor->requireTeam();
        $state = $this->state($room);

        if (!\in_array($state, ['writing', 'ending'], true)) {
            throw new IllegalActionException('Teraz nie piszemy.');
        }

        if ('ending' === $state) {
            // O tym, czy czas minal, rozstrzyga serwer. Telefon z zablokowanym
            // ekranem potrafi obudzic sie po minucie i dosylac odpowiedzi.
            if ($this->timer->expired($room->code)) {
                throw new IllegalActionException('Czas minął.');
            }

            // Kto nacisnal STOP, ten skonczyl. Inaczej STOP bylby zagraniem
            // czysto taktycznym: odcinam reszcie czas, a sam dopisuje dalej.
            if ($team->id === ($this->ctx($room)['stoppedBy'] ?? null)) {
                throw new IllegalActionException('Nacisnęliście STOP - wasze odpowiedzi są zamknięte.');
            }
        }

        $column = \is_string($payload['column'] ?? null) ? $payload['column'] : '';
        $columns = $this->columns($room);

        if (!\in_array($column, $columns, true)) {
            throw new IllegalActionException('Nie ma takiej rubryki.');
        }

        $text = \is_string($payload['text'] ?? null) ? trim($payload['text']) : '';
        $text = mb_substr($text, 0, self::MAX_ANSWER);

        $answers = $this->answers($room);
        $answers[$team->id][$column] = $text;

        $this->patchCtx($room, ['_answers' => $answers]);

        // Na telewizor idzie sama liczba wypelnionych rubryk - tresc jest tajna
        // do konca rundy, ale postep pokazuje, na kogo jeszcze czekamy.
        $filled = \count(array_filter($answers[$team->id], static fn (string $v): bool => '' !== $v));

        return [Message::toHost(new Event('progress', [
            'teamId' => $team->id,
            'filled' => $filled,
            'total' => \count($columns),
        ]))];
    }

    /**
     * STOP. Od tej chwili leci odliczanie dla wszystkich - takze dla druzyny,
     * ktora je uruchomila.
     *
     * @return list<Message>
     */
    private function stop(Room $room, Actor $actor): array
    {
        $team = $actor->isHost() ? null : $actor->requireTeam();

        // STOP wolno nacisnac dopiero z kompletna kartka. Bez tego oplacaloby
        // sie wpisac jedno slowo i uciac czas reszcie - a to psuje cala runde.
        // Host jest wyjatkiem: on konczy runde jako prowadzacy, nie jako gracz.
        if (null !== $team && !$this->isComplete($room, $team->id)) {
            throw new IllegalActionException('Najpierw wypełnijcie wszystkie rubryki.');
        }

        $this->transition($room, 'stop');

        $seconds = $this->seconds($room);
        $started = $this->timer->start($room->code, $seconds);

        $this->patchCtx($room, ['stoppedBy' => $team?->id]);

        return [
            Message::broadcast(new Event('stopped', [
                'teamId' => $team?->id,
                'teamName' => null === $team ? 'Host' : $team->name,
                'seconds' => $seconds,
            ])),
            Message::broadcast(new Event(Event::TIMER, $started)),
        ];
    }

    /** @return list<Message> */
    private function finish(Room $room, Actor $actor): array
    {
        $actor->requireHost();

        // Recznie mozna skonczyc zawsze; po STOP-ie dopiero, gdy czas faktycznie
        // minal - inaczej host odcinalby pisanie w polowie odliczania.
        if ('ending' === $this->state($room) && !$this->timer->expired($room->code)) {
            throw new IllegalActionException('Odliczanie jeszcze trwa.');
        }

        $this->transition($room, 'finish');
        $this->timer->clear($room->code);

        $results = $this->results($room);
        $this->patchCtx($room, ['results' => $results]);

        return [Message::broadcast(new Event('results', $results))];
    }

    /**
     * Uznaje albo odrzuca pojedyncza odpowiedz i przelicza wynik.
     *
     * To jest miejsce na literowke: "Kanadaa" wpisana pod litera K przechodzi
     * automatycznie, ale "Ganada" juz nie - i wtedy jedno klikniecie hosta
     * zalatwia sprawe, zamiast klotni przy stole.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<Message>
     */
    private function toggle(Room $room, array $payload, Actor $actor): array
    {
        $actor->requireHost();

        if ('scoring' !== $this->state($room)) {
            throw new IllegalActionException('Odpowiedzi poprawiamy po zakończeniu rundy.');
        }

        if (true === ($this->ctx($room)['_awarded'] ?? false)) {
            throw new IllegalActionException('Punkty już przyznane - popraw je na tablicy wyników.');
        }

        $teamId = \is_string($payload['teamId'] ?? null) ? $payload['teamId'] : '';
        $column = \is_string($payload['column'] ?? null) ? $payload['column'] : '';

        if (!\in_array($column, $this->columns($room), true) || null === $this->rooms->team($room->code, $teamId)) {
            throw new IllegalActionException('Nie ma takiej odpowiedzi.');
        }

        $answers = $this->answers($room);
        $text = trim($answers[$teamId][$column] ?? '');

        if ('' === $text) {
            throw new IllegalActionException('Pustego pola nie ma jak uznać.');
        }

        $overrides = $this->overrides($room);
        $current = $this->results($room)['cells'][$teamId][$column]['points'] ?? 0;

        // Przelaczamy wzgledem tego, co widac na ekranie: odpowiedz punktujaca
        // idzie do odrzuconych, niepunktujaca - do uznanych.
        $overrides[$teamId][$column] = 0 === $current;

        $this->patchCtx($room, ['_overrides' => $overrides]);

        $results = $this->results($room);
        $this->patchCtx($room, ['results' => $results]);

        return [Message::broadcast(new Event('results', $results))];
    }

    /**
     * Dopisuje punkty rundy do tabla. Raz - powtorne klikniecie nie doda ich
     * drugi raz, bo tablo jest wspolne dla wszystkich gier wieczoru.
     *
     * @return list<Message>
     */
    private function award(Room $room, Actor $actor): array
    {
        $actor->requireHost();

        if ('scoring' !== $this->state($room)) {
            throw new IllegalActionException('Najpierw zakończ rundę.');
        }

        if (true === ($this->ctx($room)['_awarded'] ?? false)) {
            throw new IllegalActionException('Punkty za tę rundę już poszły na tablo.');
        }

        $results = $this->results($room);
        $this->patchCtx($room, ['_awarded' => true, 'results' => $results]);

        $messages = [];

        foreach ($results['totals'] as $teamId => $points) {
            if (0 === $points) {
                continue;
            }

            $total = $this->scoreboard->add($room->code, $teamId, $points);
            $messages[] = Message::broadcast(new Event(Event::SCORE, ['teamId' => $teamId, 'total' => $total]));
        }

        $messages[] = Message::broadcast(new Event('awarded', ['totals' => $results['totals']]));

        return $messages;
    }

    /** @return list<Message> */
    private function nextRound(Room $room, Actor $actor): array
    {
        $actor->requireHost();
        $this->transition($room, 'next_round');
        $this->timer->clear($room->code);

        $this->patchCtx($room, ['letter' => null, 'results' => null, 'stoppedBy' => null]);

        return [Message::broadcast(new Event('round_reset', []))];
    }

    public function hostView(Room $room): array
    {
        $ctx = $this->ctx($room);
        $state = $this->state($room);

        // Odpowiedzi ida na telewizor dopiero po rundzie. W trakcie pisania
        // host widzi wylacznie postep - telewizor stoi w tym samym pokoju,
        // co grajacy.
        $results = 'scoring' === $state ? $this->results($room) : null;

        return $this->publicView($room) + [
            'results' => $results,
            'awarded' => true === ($ctx['_awarded'] ?? false),
            'presets' => Kolumny::choices(),
            'progress' => $this->progress($room),
            'teams' => array_map(
                static fn (Team $team): array => ['id' => $team->id, 'name' => $team->name, 'color' => $team->color],
                $this->rooms->teams($room->code),
            ),
        ];
    }

    public function playerView(Room $room, Team $team): array
    {
        $state = $this->state($room);
        $answers = $this->answers($room);

        $stoppedBy = $this->ctx($room)['stoppedBy'] ?? null;

        return $this->publicView($room) + [
            // Wylacznie swoje - cudze odpowiedzi to caly sekret tej gry.
            'answers' => $answers[$team->id] ?? [],
            'iStopped' => $team->id === $stoppedBy,
            'complete' => $this->isComplete($room, $team->id),
            'canWrite' => 'writing' === $state
                || ('ending' === $state
                    && $team->id !== $stoppedBy
                    && !$this->timer->expired($room->code)),
            'myPoints' => 'scoring' === $state ? ($this->results($room)['totals'][$team->id] ?? 0) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function publicView(Room $room): array
    {
        $ctx = $this->ctx($room);

        return [
            'state' => $this->state($room),
            'letter' => $ctx['letter'] ?? null,
            'columns' => $this->columns($room),
            'preset' => $ctx['preset'] ?? Kolumny::DEFAULT_PRESET,
            'round' => $ctx['round'] ?? 0,
            'seconds' => $this->seconds($room),
            'stoppedBy' => $ctx['stoppedBy'] ?? null,
            'deadline' => $this->timer->deadline($room->code),
            'serverNow' => (int) (microtime(true) * 1000),
        ];
    }

    /**
     * @return array{
     *     cells: array<string, array<string, array{text: string, points: int, status: string, forced: bool}>>,
     *     totals: array<string, int>
     * }
     */
    private function results(Room $room): array
    {
        $ctx = $this->ctx($room);
        $letter = \is_string($ctx['letter'] ?? null) ? $ctx['letter'] : '';
        $answers = $this->answers($room);

        // Druzyna, ktora nie wpisala nic, tez musi znalezc sie w tabeli -
        // inaczej zniknelaby z ekranu wynikow i wygladalo by to na blad.
        foreach ($this->rooms->teams($room->code) as $team) {
            $answers[$team->id] ??= [];
        }

        return Punktacja::score($letter, $this->columns($room), $answers, $this->overrides($room));
    }

    /** Czy druzyna ma wypelniona kazda rubryke. */
    private function isComplete(Room $room, string $teamId): bool
    {
        $row = $this->answers($room)[$teamId] ?? [];

        foreach ($this->columns($room) as $column) {
            if ('' === trim($row[$column] ?? '')) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, int> teamId => ile rubryk wypelnionych */
    private function progress(Room $room): array
    {
        $out = [];

        foreach ($this->answers($room) as $teamId => $row) {
            $out[$teamId] = \count(array_filter($row, static fn (string $text): bool => '' !== $text));
        }

        return $out;
    }

    /** @return array<string, array<string, string>> */
    private function answers(Room $room): array
    {
        $raw = $this->ctx($room)['_answers'] ?? null;
        $out = [];

        if (!\is_array($raw)) {
            return $out;
        }

        foreach ($raw as $teamId => $row) {
            if (!\is_string($teamId) || !\is_array($row)) {
                continue;
            }

            foreach ($row as $column => $text) {
                if (\is_string($column) && \is_string($text)) {
                    $out[$teamId][$column] = $text;
                }
            }

            $out[$teamId] ??= [];
        }

        return $out;
    }

    /** @return array<string, array<string, bool>> */
    private function overrides(Room $room): array
    {
        $raw = $this->ctx($room)['_overrides'] ?? null;
        $out = [];

        if (!\is_array($raw)) {
            return $out;
        }

        foreach ($raw as $teamId => $row) {
            if (!\is_string($teamId) || !\is_array($row)) {
                continue;
            }

            foreach ($row as $column => $accepted) {
                if (\is_string($column) && \is_bool($accepted)) {
                    $out[$teamId][$column] = $accepted;
                }
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function columns(Room $room): array
    {
        $ctx = $this->ctx($room);
        $columns = $ctx['columns'] ?? null;

        if (\is_array($columns) && [] !== $columns) {
            return array_values(array_filter($columns, '\is_string'));
        }

        $preset = \is_string($ctx['preset'] ?? null) ? $ctx['preset'] : Kolumny::DEFAULT_PRESET;

        return Kolumny::columns($preset);
    }

    private function seconds(Room $room): int
    {
        $seconds = $this->ctx($room)['seconds'] ?? null;

        return \is_int($seconds) ? $seconds : self::DEFAULT_SECONDS;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        return \is_array($value) ? array_values(array_filter($value, '\is_string')) : [];
    }
}
