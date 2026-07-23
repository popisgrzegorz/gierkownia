<?php

declare(strict_types=1);

namespace App\Game\MelodiaQuiz;

use App\Content\TrackDeck;
use App\Entity\Track;
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
 * "Jaka to melodia" w wariancie testowym: cztery tytuly, jeden prawdziwy.
 *
 * Osobny modul, a nie tryb w MelodiaGame - przebieg jest inny na wylot.
 * Tam liczy sie refleks jednej druzyny (buzzer blokuje pozostale), tu wszyscy
 * odpowiadaja rownoczesnie i w tajemnicy, a rozstrzyga zegar. Wcisniecie tego
 * w jeden automat stanow dalo by maszyne, w ktorej polowa przejsc obowiazuje
 * tylko w polowie przypadkow.
 *
 * Trescia dzieli sie z wariantem buzzerowym (`TrackDeck`), wiec te same
 * kategorie graja w obu - nikt nie wprowadza nagran drugi raz.
 *
 * Odpowiedzi sa tajne do konca rundy: siedza pod kluczem z podkresleniem,
 * ktorego rdzen nie wypuszcza, a telefon dostaje wylacznie swoja. Tak samo
 * poprawna odpowiedz - na ekran hosta idzie dopiero z wynikami, bo na
 * telewizor patrzy caly pokoj.
 */
final class MelodiaQuizGame extends AbstractGame
{
    /** Ile trwa runda; host moze podac wlasny czas przy starcie. */
    private const DEFAULT_SECONDS = 30;

    private const MIN_SECONDS = 5;
    private const MAX_SECONDS = 180;

    /** Punkty za trafiony tytul. */
    private const POINTS = 10;

    /** Ile odpowiedzi na pytanie - stala gry, nie ustawienie. */
    public const OPTIONS = 4;

    public function __construct(
        GameFsm $fsm,
        GameState $gameState,
        private readonly TrackDeck $deck,
        private readonly Scoreboard $scoreboard,
        private readonly GameTimer $timer,
        private readonly RoomManager $rooms,
    ) {
        parent::__construct($fsm, $gameState);
    }

    public static function type(): string
    {
        return 'melodia-quiz';
    }

    public function name(): string
    {
        return 'Jaka to melodia — 1 z 4';
    }

    public function definition(): GameDefinition
    {
        return new GameDefinition(
            type: self::type(),
            initialState: 'idle',
            states: ['idle', 'ready', 'answering', 'results'],
            transitions: [
                // Losowanie wolno powtorzyc przed startem ("Inny utwor") - host
                // czasem trafi na kawalek, ktory wlasnie leci z glosnika obok.
                new Transition('draw', ['idle', 'ready'], 'ready'),
                new Transition('start', 'ready', 'answering'),
                new Transition('finish', 'answering', 'results'),
                // Nikt nie trafil - ten sam utwor leci jeszcze raz, z tymi
                // samymi odpowiedziami. Bez tego runda konczylaby sie zerem
                // i zagadka zostawalaby nierozwiazana.
                new Transition('again', 'results', 'answering'),
                new Transition('next_round', 'results', 'idle'),
            ],
        );
    }

    public function handleAction(Room $room, string $action, array $payload, Actor $actor): array
    {
        return match ($action) {
            'pick_category' => $this->pickCategory($room, $payload, $actor),
            'next_track' => $this->nextTrack($room, $actor),
            'start' => $this->start($room, $payload, $actor),
            'answer' => $this->answer($room, $payload, $actor),
            'finish' => $this->finish($room, $actor),
            'again' => $this->again($room, $actor),
            'next_round' => $this->nextRound($room, $actor),
            default => throw new IllegalActionException(sprintf('Test melodii nie zna akcji "%s".', $action)),
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

        $slugs = $this->deck->known($this->slugs($payload));

        if ([] === $slugs) {
            throw new IllegalActionException('Nie ma takiej kategorii.');
        }

        $this->patchCtx($room, [
            'categories' => $slugs,
            'categoryName' => $this->deck->name($slugs),
            'trackTotal' => $this->deck->countTracks($slugs),
            'trackNumber' => 0,
            '_played' => [],
            '_trackId' => null,
            'options' => [],
        ]);

        return [Message::broadcast(new Event('category_picked', [
            'categoryName' => $this->deck->name($slugs),
            'items' => $this->deck->countTracks($slugs),
        ]))];
    }

    /**
     * Wybor kategorii z zadania. Pojedynczy `category` zostaje obslugiwany:
     * tak strzela przycisk fizyczny i tak wygladaja starsze zapisy w Redisie.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    private function slugs(array $payload): array
    {
        $raw = $payload['categories'] ?? $payload['category'] ?? null;

        if (\is_string($raw)) {
            return '' === $raw ? [] : [$raw];
        }

        return \is_array($raw) ? array_values(array_filter($raw, '\is_string')) : [];
    }

    /** @return list<Message> */
    private function nextTrack(Room $room, Actor $actor): array
    {
        $actor->requireHost();

        if ([] === $this->picked($room)) {
            throw new IllegalActionException('Najpierw wybierz kategorię.');
        }

        $track = $this->drawTrack($room);

        if (null === $track) {
            throw new IllegalActionException('Kategoria się skończyła - wybierz inną albo zrestartuj grę.');
        }

        return $this->serve($room, $track);
    }

    /** Losuje nagranie, ktorego jeszcze nie bylo - null, gdy pula pusta albo wyczerpana. */
    private function drawTrack(Room $room): ?Track
    {
        $picked = $this->picked($room);

        if ([] === $picked) {
            return null;
        }

        return $this->deck->drawNext($picked, $this->intList($this->ctx($room)['_played'] ?? null));
    }

    /**
     * Uklada pytanie z wylosowanego nagrania i rozglasza je do pokoju.
     *
     * @return list<Message>
     */
    private function serve(Room $room, Track $track): array
    {
        $ctx = $this->ctx($room);
        $picked = $this->picked($room);
        $played = $this->intList($ctx['_played'] ?? null);

        // Podpis, nie sam tytul: sam tytul bywa bezuzyteczny przy coverach,
        // a podpis mowi, ktore wykonanie wlasnie leci.
        // Blednne odpowiedzi z tych samych kategorii, co utwor - obca stylistyka
        // zdradzalaby sie sama.
        $options = Wybory::build($track->getLabel(), $this->deck->otherLabels($picked, (int) $track->getId()));

        if (\count($options['options']) < self::OPTIONS) {
            throw new IllegalActionException(sprintf(
                'W tej kategorii są %d nagrania - do testu 1 z 4 potrzeba przynajmniej czterech.',
                \count($options['options']),
            ));
        }

        $this->transition($room, 'draw');

        $played[] = (int) $track->getId();

        $this->patchCtx($room, [
            '_trackId' => $track->getId(),
            '_correct' => $options['correct'],
            '_answers' => [],
            '_played' => $played,
            'options' => $options['options'],
            'trackNumber' => \count($played),
            'revealed' => null,
            'attempt' => 1,
        ]);

        // Telefony dostaja odpowiedzi do wyboru, ale nie to, ktora jest dobra.
        return [Message::broadcast(new Event('question_ready', [
            'number' => \count($played),
            'total' => $ctx['trackTotal'] ?? 0,
            'options' => $options['options'],
        ]))];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<Message>
     */
    private function start(Room $room, array $payload, Actor $actor): array
    {
        $actor->requireHost();

        $seconds = \is_int($payload['seconds'] ?? null) ? $payload['seconds'] : $this->seconds($room);

        if ($seconds < self::MIN_SECONDS || $seconds > self::MAX_SECONDS) {
            throw new IllegalActionException(sprintf(
                'Czas rundy musi mieścić się między %d a %d sekundami.',
                self::MIN_SECONDS,
                self::MAX_SECONDS,
            ));
        }

        $this->transition($room, 'start');
        $this->patchCtx($room, ['seconds' => $seconds, '_answers' => []]);

        $started = $this->timer->start($room->code, $seconds);

        return [Message::broadcast(new Event(Event::TIMER, $started))];
    }

    /**
     * Odpowiedz druzyny. Jedna na runde - zablokowana od razu po wyslaniu.
     *
     * Bez blokady wskaznik "kto juz odpowiedzial" na telewizorze klamalby,
     * a przy stole to on decyduje, czy host czeka, czy konczy runde.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<Message>
     */
    private function answer(Room $room, array $payload, Actor $actor): array
    {
        $team = $actor->requireTeam();

        if ('answering' !== $this->state($room)) {
            throw new IllegalActionException('Teraz nie odpowiadamy.');
        }

        // O uplywie czasu rozstrzyga serwer, nie zegar telefonu.
        if ($this->timer->expired($room->code)) {
            throw new IllegalActionException('Czas minął.');
        }

        $answers = $this->answers($room);

        if (isset($answers[$team->id])) {
            throw new IllegalActionException('Odpowiedź już wysłana - liczy się pierwsza.');
        }

        $choice = $payload['option'] ?? null;
        $options = $this->options($room);

        if (!\is_int($choice) || !isset($options[$choice])) {
            throw new IllegalActionException('Nie ma takiej odpowiedzi.');
        }

        $answers[$team->id] = $choice;
        $this->patchCtx($room, ['_answers' => $answers]);

        // Na telewizor idzie sama informacja, KTO odpowiedzial - nigdy co.
        $messages = [Message::broadcast(new Event('answered', [
            'teamId' => $team->id,
            'answered' => \count($answers),
            'teams' => \count($this->rooms->teams($room->code)),
        ]))];

        // Wszyscy juz odpowiedzieli - nie ma na co czekac, choc czas jeszcze
        // leci. Runda konczy sie tu, a nie osobnym kliknieciem hosta.
        if (\count($answers) >= \count($this->rooms->teams($room->code))) {
            return [...$messages, ...$this->closeRound($room)];
        }

        return $messages;
    }

    /** @return list<Message> */
    private function finish(Room $room, Actor $actor): array
    {
        $actor->requireHost();

        if ('answering' !== $this->state($room)) {
            throw new IllegalActionException('Runda już zamknięta.');
        }

        return $this->closeRound($room);
    }

    /**
     * Zamyka runde: odslania tytul, przyznaje punkty i pokazuje, kto co wybral.
     *
     * @return list<Message>
     */
    private function closeRound(Room $room): array
    {
        $this->transition($room, 'finish');
        $this->timer->clear($room->code);

        $correct = $this->correct($room);
        $options = $this->options($room);
        $answers = $this->answers($room);

        $winners = [];
        $messages = [];

        foreach ($answers as $teamId => $choice) {
            if ($choice !== $correct) {
                continue;
            }

            $winners[] = $teamId;
            $total = $this->scoreboard->add($room->code, $teamId, self::POINTS);
            $messages[] = Message::broadcast(new Event(Event::SCORE, ['teamId' => $teamId, 'total' => $total]));
        }

        $this->patchCtx($room, [
            'revealed' => $options[$correct] ?? null,
            'correct' => $correct,
            'answers' => $answers,
            'winners' => $winners,
        ]);

        $messages[] = Message::broadcast(new Event('results', [
            'correct' => $correct,
            'title' => $options[$correct] ?? null,
            'answers' => $answers,
            'winners' => $winners,
            'points' => self::POINTS,
        ]));

        return $messages;
    }

    /**
     * Jeszcze raz ten sam utwor - wolno tylko wtedy, gdy nikt nie trafil.
     * Powtorka po trafieniu bylaby druga szansa na te same punkty.
     *
     * @return list<Message>
     */
    private function again(Room $room, Actor $actor): array
    {
        $actor->requireHost();

        $ctx = $this->ctx($room);
        $winners = \is_array($ctx['winners'] ?? null) ? $ctx['winners'] : [];

        if ([] !== $winners) {
            throw new IllegalActionException('Ktoś już trafił - powtórka nie ma sensu.');
        }

        $this->transition($room, 'again');

        $attempt = \is_int($ctx['attempt'] ?? null) ? $ctx['attempt'] + 1 : 2;

        $this->patchCtx($room, [
            '_answers' => [],
            'answers' => [],
            'winners' => [],
            'revealed' => null,
            'correct' => null,
            'attempt' => $attempt,
        ]);

        $started = $this->timer->start($room->code, $this->seconds($room));

        return [
            Message::broadcast(new Event('again', ['attempt' => $attempt])),
            Message::broadcast(new Event(Event::TIMER, $started)),
        ];
    }

    /**
     * "Nastepny utwor" losuje od razu. Osobne kliknięcie "Losuj" bylo pusta
     * przerwa w rytmie wieczoru - host i tak zawsze klikal je zaraz potem.
     *
     * @return list<Message>
     */
    private function nextRound(Room $room, Actor $actor): array
    {
        $actor->requireHost();
        $this->transition($room, 'next_round');
        $this->timer->clear($room->code);

        $this->patchCtx($room, [
            'options' => [],
            'answers' => [],
            'winners' => [],
            'revealed' => null,
            'correct' => null,
            '_trackId' => null,
        ]);

        $messages = [Message::broadcast(new Event('round_reset', []))];
        $track = $this->drawTrack($room);

        // Kategoria sie skonczyla - zostajemy w idle z czystym ekranem, zamiast
        // wywalac akcje juz po zmianie stanu. Host wybiera inna albo restartuje.
        return null === $track ? $messages : [...$messages, ...$this->serve($room, $track)];
    }

    public function hostView(Room $room): array
    {
        $ctx = $this->ctx($room);
        $track = $this->track($room);

        return $this->publicView($room) + [
            // Do odtwarzania wystarczy id - adres nie zdradza tytulu.
            'track' => null === $track ? null : ['id' => $track->getId(), 'startAt' => $track->getStartAt()],
            'categories' => $this->deck->availableCategories(),
            'teams' => array_map(
                static fn (Team $team): array => ['id' => $team->id, 'name' => $team->name, 'color' => $team->color],
                $this->rooms->teams($room->code),
            ),
            'answeredBy' => array_keys($this->answers($room)),
            'categoryName' => $ctx['categoryName'] ?? null,
            // Zaznaczone kategorie - po nich ekran hosta poznaje, czy jest już
            // co losować. Lista, bo wybór bywa mieszany.
            'picked' => $this->picked($room),
        ];
    }

    public function playerView(Room $room, Team $team): array
    {
        return $this->publicView($room) + [
            // Wylacznie swoja odpowiedz - cudze to caly sekret tej rundy.
            'myAnswer' => $this->answers($room)[$team->id] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function publicView(Room $room): array
    {
        $ctx = $this->ctx($room);
        $state = $this->state($room);

        return [
            'state' => $state,
            'options' => $this->options($room),
            // Poprawna odpowiedz wychodzi dopiero z wynikami - na ten ekran
            // patrzy caly pokoj, razem z odpowiadajacymi.
            'correct' => 'results' === $state ? $this->correct($room) : null,
            'revealed' => 'results' === $state ? ($ctx['revealed'] ?? null) : null,
            'answers' => 'results' === $state ? ($ctx['answers'] ?? []) : [],
            'winners' => 'results' === $state ? ($ctx['winners'] ?? []) : [],
            'trackNumber' => $ctx['trackNumber'] ?? 0,
            'trackTotal' => $ctx['trackTotal'] ?? 0,
            'attempt' => $ctx['attempt'] ?? 1,
            'seconds' => $this->seconds($room),
            'points' => self::POINTS,
            'deadline' => $this->timer->deadline($room->code),
            'serverNow' => (int) (microtime(true) * 1000),
        ];
    }

    private function track(Room $room): ?Track
    {
        $id = $this->ctx($room)['_trackId'] ?? null;

        return \is_int($id) ? $this->deck->find($id) : null;
    }

    /** @return list<string> */
    private function options(Room $room): array
    {
        $options = $this->ctx($room)['options'] ?? null;

        return \is_array($options) ? array_values(array_filter($options, '\is_string')) : [];
    }

    private function correct(Room $room): int
    {
        $correct = $this->ctx($room)['_correct'] ?? null;

        return \is_int($correct) ? $correct : -1;
    }

    /** @return array<string, int> */
    private function answers(Room $room): array
    {
        $raw = $this->ctx($room)['_answers'] ?? null;
        $out = [];

        if (!\is_array($raw)) {
            return $out;
        }

        foreach ($raw as $teamId => $choice) {
            if (\is_string($teamId) && \is_int($choice)) {
                $out[$teamId] = $choice;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function picked(Room $room): array
    {
        $slugs = $this->ctx($room)['categories'] ?? null;

        return \is_array($slugs) ? array_values(array_filter($slugs, '\is_string')) : [];
    }

    private function seconds(Room $room): int
    {
        $seconds = $this->ctx($room)['seconds'] ?? null;

        return \is_int($seconds) ? $seconds : self::DEFAULT_SECONDS;
    }

    /** @return list<int> */
    private function intList(mixed $value): array
    {
        return \is_array($value) ? array_values(array_filter($value, '\is_int')) : [];
    }
}
