<?php

declare(strict_types=1);

namespace App\Game\OstatniaLitera;

use App\Core\Game\AbstractGame;
use App\Core\Game\Actor;
use App\Core\Game\Fsm\GameFsm;
use App\Core\Game\GameDefinition;
use App\Core\Game\GameState;
use App\Core\Game\IllegalActionException;
use App\Core\Game\Transition;
use App\Core\Realtime\Event;
use App\Core\Realtime\Message;
use App\Core\Realtime\Publisher;
use App\Core\Room\Room;
use App\Core\Room\RoomManager;
use App\Core\Room\Team;
use App\Core\Score\Scoreboard;
use App\Core\Timer\GameTimer;

/**
 * Ostatnia litera: druzyny po kolei podaja slowo zaczynajace sie na ostatnia
 * litere poprzedniego.
 *
 * Wyscig do progu punktow, z eliminacja jako siatka bezpieczenstwa - obie
 * zasady na zyczenie uzytkownika (poprzednia wersja miala plaskie +1/-1
 * kredyty bez progu zwyciestwa). Host ustala przy starcie punkty startowe
 * i prog zwyciestwa (musi byc wyzszy niz start, inaczej gra konczylaby sie,
 * zanim ktokolwiek cokolwiek zrobil). Trafienie oddaje TYLE PUNKTOW, ILE
 * SEKUND ZOSTALO na zegarze w chwili odpowiedzi (10s na słowo, odpowiedz po
 * 7s = 3 punkty) - im szybsza odpowiedz, tym wiecej warta. Spoznienie kosztuje
 * stala kare (TIMEOUT_PENALTY). Zero punktow lub mniej = odpadniecie,
 * dokladnie jak w poprzedniej wersji z kredytami - to ta sama siatka
 * bezpieczenstwa, tylko waluta jest teraz zmiennej wartosci, nie plaskim ±1.
 *
 * Punkt za trafienie liczy sie w CHWILI PODANIA SLOWA, nie w chwili, gdy
 * host skonczy sedziowac - inaczej zwlekajacy host oszukiwalby druzyne
 * z naleznych punktow. Dla sciezki auto-akceptacji (submit, slowo rozpoznane)
 * to ten sam moment. Dla sciezki sedziowskiej (submit -> hold -> rule) trzeba
 * zapamietac wartosc W MOMENCIE ZGLOSZENIA (`pendingPoints`), bo zegar stoi
 * przez cala narade hosta i po jej stronie nie ma juz czego mierzyc.
 *
 * Wspolne tablo (Scoreboard) JEST punktacja - bez osobnego mirrorowania jak
 * w poprzedniej wersji (tam "credits" i tablo byly dwoma zapisami tej samej
 * liczby, bo tablo umialo tylko +1/-1 kredytu). Tu kazda zmiana wprost leci
 * przez Scoreboard::add(), a `total` z jego odpowiedzi rozstrzyga i prog
 * zwyciestwa, i eliminacje - jedno zrodlo prawdy zamiast dwoch zsynchronizowanych.
 *
 * Slowo sprawdza WordJudge (hunspell + slownik PL) automatycznie; rozpoznane
 * wchodzi od razu, nierozpoznane zatrzymuje runde w stanie `judging` - host
 * jest ostatecznym sedzia, dokladnie jak Wykaz w Panstwach-miastach nie udaje
 * znajomosci calego jezyka polskiego.
 *
 * Kolejnosc drużyn trzymamy we wlasnym kontekscie (`order`/`turn`), nie
 * w TurnManagerze: ten nie potrafi pomijac wyeliminowanych przy `next()`
 * (goly modulo-INCR po stalej liscie) - a to gra, w ktorej trzeba.
 */
final class OstatniaLiteraGame extends AbstractGame
{
    private const MIN_TEAMS = 2;

    private const DEFAULT_STARTING_POINTS = 10;
    private const MIN_STARTING_POINTS = 1;
    private const MAX_STARTING_POINTS = 999;

    private const DEFAULT_WIN_THRESHOLD = 50;
    private const MAX_WIN_THRESHOLD = 9999;

    /** Kara za spoznienie - stala, niezalezna od czasu rundy. */
    private const TIMEOUT_PENALTY = 2;

    /** @var list<int> */
    private const SECONDS_CHOICES = [5, 10, 15, 20, 30];

    /** Miekkie spolgloski koncowe i ich dwuznak na poczatku kolejnego slowa. */
    private const DIGRAPHS = ['ń' => 'ni', 'ć' => 'ci', 'ś' => 'si', 'ź' => 'zi'];

    /**
     * Samogloski nosowe na koncu slowa: zadne polskie slowo nie zaczyna sie na
     * "ą" (na "ę" praktycznie tez nie), a "gęślą" konczy sie wlasnie na "ą" -
     * nastepna druzyna nie miala czym odpowiedziec i obie traciły punkty za
     * spoznienie do konca gry. Wymagana jest wiec litera bazowa (a, e).
     */
    private const NASAL_FALLBACKS = ['ą' => 'a', 'ę' => 'e'];

    /** Tresc porownywana w submit(), zeby odroznic powtorke od innych bledow checkWord(). */
    private const DUPLICATE_MESSAGE = 'To słowo już padło w tej grze.';

    public function __construct(
        GameFsm $fsm,
        GameState $gameState,
        private readonly RoomManager $rooms,
        private readonly Scoreboard $scoreboard,
        private readonly GameTimer $timer,
        private readonly WordJudge $judge,
        private readonly Publisher $publisher,
    ) {
        parent::__construct($fsm, $gameState);
    }

    public static function type(): string
    {
        return 'ostatnia-litera';
    }

    public function name(): string
    {
        return 'Ostatnia litera';
    }

    public function definition(): GameDefinition
    {
        return new GameDefinition(
            type: self::type(),
            initialState: 'idle',
            states: ['idle', 'playing', 'judging', 'finished'],
            transitions: [
                new Transition('start', 'idle', 'playing'),
                // Slowo nierozpoznane przez slownik - pauza na werdykt hosta.
                new Transition('hold', 'playing', 'judging'),
                new Transition('rule', 'judging', 'playing'),
                new Transition('finish', 'playing', 'finished'),
                new Transition('restart', 'finished', 'idle'),
            ],
        );
    }

    /** @param array<string, mixed> $payload */
    public function handleAction(Room $room, string $action, array $payload, Actor $actor): array
    {
        return match ($action) {
            'start' => $this->start($room, $payload, $actor),
            'submit' => $this->submit($room, $payload, $actor),
            'rule' => $this->rule($room, $payload, $actor),
            'timeout' => $this->timeout($room, $actor),
            'restart' => $this->restart($room, $actor),
            default => throw new IllegalActionException(sprintf('Ostatnia litera nie zna akcji "%s".', $action)),
        };
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<Message>
     */
    private function start(Room $room, array $payload, Actor $actor): array
    {
        $actor->requireHost();

        $seconds = \is_int($payload['seconds'] ?? null) ? $payload['seconds'] : 0;

        if (!\in_array($seconds, self::SECONDS_CHOICES, true)) {
            throw new IllegalActionException(sprintf(
                'Czas tury musi wynosić %s sekund.',
                implode(', ', self::SECONDS_CHOICES),
            ));
        }

        $startingPoints = \is_int($payload['startingPoints'] ?? null)
            ? $payload['startingPoints']
            : self::DEFAULT_STARTING_POINTS;

        if ($startingPoints < self::MIN_STARTING_POINTS || $startingPoints > self::MAX_STARTING_POINTS) {
            throw new IllegalActionException(sprintf(
                'Punkty startowe muszą mieścić się między %d a %d.',
                self::MIN_STARTING_POINTS,
                self::MAX_STARTING_POINTS,
            ));
        }

        $winThreshold = \is_int($payload['winThreshold'] ?? null)
            ? $payload['winThreshold']
            : self::DEFAULT_WIN_THRESHOLD;

        if ($winThreshold > self::MAX_WIN_THRESHOLD) {
            throw new IllegalActionException(sprintf('Próg zwycięstwa nie może przekraczać %d.', self::MAX_WIN_THRESHOLD));
        }

        if ($winThreshold <= $startingPoints) {
            throw new IllegalActionException('Próg zwycięstwa musi być wyższy niż punkty startowe.');
        }

        $teams = $this->rooms->teams($room->code);

        if (\count($teams) < self::MIN_TEAMS) {
            throw new IllegalActionException('Potrzeba przynajmniej dwóch drużyn.');
        }

        $order = array_map(static fn (Team $team): string => $team->id, $teams);

        $this->transition($room, 'start');
        $this->patchCtx($room, [
            'order' => $order,
            'turn' => $order[0],
            'eliminated' => [],
            'usedWords' => [],
            'lastWord' => null,
            'pendingWord' => null,
            'pendingPoints' => null,
            'seconds' => $seconds,
            'startingPoints' => $startingPoints,
            'winThreshold' => $winThreshold,
            'winner' => null,
        ]);

        // Tablo od razu ma pokazywac punkty startowe - inaczej druzyna, ktora
        // jeszcze nic nie zrobila, wygladalaby na "0 punktow".
        foreach ($order as $teamId) {
            $current = $this->scoreboard->get($room->code, $teamId);
            $this->scoreboard->add($room->code, $teamId, $startingPoints - $current);
        }

        $started = $this->timer->start($room->code, $seconds);

        return [
            Message::broadcast(new Event('game_started', ['turn' => $order[0]])),
            Message::broadcast(new Event(Event::TIMER, $started)),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<Message>
     */
    private function submit(Room $room, array $payload, Actor $actor): array
    {
        $team = $actor->requireTeam();

        if ('playing' !== $this->state($room)) {
            throw new IllegalActionException('Teraz nikt nie podaje słowa.');
        }

        if ($this->turn($room) !== $team->id) {
            throw new IllegalActionException('Nie twoja kolej.');
        }

        $word = \is_string($payload['word'] ?? null) ? trim($payload['word']) : '';
        $error = $this->checkWord($room, $word);

        if (null !== $error) {
            // Powtorka slowa dostaje wlasny sygnal dla TV (komunikat + dzwiek) -
            // reszcie odrzuceń (za krotkie, zla litera) wystarczy blad na telefonie,
            // to nie sa momenty, ktore caly stol chce zobaczyc naraz.
            if (self::DUPLICATE_MESSAGE === $error) {
                $this->publisher->toHost($room->code, new Event('word_duplicate', [
                    'teamId' => $team->id,
                    'word' => $word,
                ]));
            }

            throw new IllegalActionException($error);
        }

        // Punkty licza sie TERAZ, w chwili zgloszenia - nie po ewentualnym
        // sedziowaniu, ktore moze trwac dowolnie dlugo.
        $points = $this->pointsForRemainingTime($room);

        if ($this->judge->isKnown($word)) {
            $result = $this->applyAcceptedWord($room, $team->id, $word, $points);

            if ($result['finished']) {
                return [
                    Message::broadcast(new Event('word_accepted', ['teamId' => $team->id, 'word' => $word, 'points' => $points])),
                    Message::broadcast(new Event('game_over', ['winner' => $team->id])),
                ];
            }

            $started = $this->timer->start($room->code, $this->seconds($room));

            return [
                Message::broadcast(new Event('word_accepted', [
                    'teamId' => $team->id,
                    'word' => $word,
                    'points' => $points,
                    'turn' => $result['next'],
                ])),
                Message::broadcast(new Event(Event::TIMER, $started)),
            ];
        }

        // Nierozpoznane - runda czeka na werdykt hosta, zegar staje. Punkty
        // za TEN moment zapamietujemy, bo po zatrzymaniu zegara nie ma juz
        // z czego ich policzyc.
        $this->transition($room, 'hold');
        $this->patchCtx($room, ['pendingWord' => $word, 'pendingPoints' => $points]);
        $this->timer->clear($room->code);

        return [Message::broadcast(new Event('word_pending', ['teamId' => $team->id, 'word' => $word]))];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<Message>
     */
    private function rule(Room $room, array $payload, Actor $actor): array
    {
        $actor->requireHost();

        if ('judging' !== $this->state($room)) {
            throw new IllegalActionException('Nikt teraz nie czeka na werdykt.');
        }

        $word = $this->pendingWord($room);
        $teamId = $this->turn($room);
        $points = $this->pendingPoints($room);

        if (null === $word || null === $teamId) {
            throw new IllegalActionException('Brak słowa czekającego na ocenę.');
        }

        $accept = true === ($payload['accept'] ?? false);

        $this->transition($room, 'rule');
        $this->patchCtx($room, ['pendingWord' => null, 'pendingPoints' => null]);

        if (!$accept) {
            // Odrzucone tak jak zwykla pomylka - bez kary, ta sama druzyna
            // probuje ponownie ze swiezym, pelnym czasem.
            $started = $this->timer->start($room->code, $this->seconds($room));

            return [
                Message::broadcast(new Event('word_ruled', ['accepted' => false, 'word' => $word, 'teamId' => $teamId])),
                Message::broadcast(new Event(Event::TIMER, $started)),
            ];
        }

        $result = $this->applyAcceptedWord($room, $teamId, $word, $points);

        if ($result['finished']) {
            return [
                Message::broadcast(new Event('word_ruled', ['accepted' => true, 'word' => $word, 'points' => $points, 'teamId' => $teamId])),
                Message::broadcast(new Event('game_over', ['winner' => $teamId])),
            ];
        }

        $started = $this->timer->start($room->code, $this->seconds($room));

        return [
            Message::broadcast(new Event('word_ruled', [
                'accepted' => true,
                'word' => $word,
                'points' => $points,
                'teamId' => $teamId,
                'turn' => $result['next'],
            ])),
            Message::broadcast(new Event(Event::TIMER, $started)),
        ];
    }

    /** @return list<Message> */
    private function timeout(Room $room, Actor $actor): array
    {
        $actor->requireHost();

        if ('playing' !== $this->state($room)) {
            throw new IllegalActionException('Teraz nie ma odliczania.');
        }

        // O uplywie czasu rozstrzyga serwer, nie zegar telewizora.
        if (!$this->timer->expired($room->code)) {
            throw new IllegalActionException('Czas jeszcze nie minął.');
        }

        $teamId = $this->turn($room);

        if (null === $teamId) {
            throw new IllegalActionException('Nikt teraz nie ma tury.');
        }

        $total = $this->scoreboard->add($room->code, $teamId, -self::TIMEOUT_PENALTY);

        if ($total > 0) {
            // Przezyla, ale traci ture - inaczej oplacaloby sie nie
            // odpowiadac wcale, majac zapas punktow.
            $order = $this->order($room);
            $eliminated = $this->eliminated($room);
            $next = $this->nextAlive($order, $teamId, $eliminated);
            $this->patchCtx($room, ['turn' => $next]);
            $started = $this->timer->start($room->code, $this->seconds($room));

            return [
                Message::broadcast(new Event('turn_missed', ['teamId' => $teamId, 'total' => $total, 'turn' => $next])),
                Message::broadcast(new Event(Event::TIMER, $started)),
            ];
        }

        $eliminated = $this->eliminated($room);
        $eliminated[] = $teamId;
        $this->patchCtx($room, ['eliminated' => $eliminated]);

        $order = $this->order($room);
        $alive = array_values(array_diff($order, $eliminated));

        if (\count($alive) <= 1) {
            $winner = $alive[0] ?? null;

            $this->transition($room, 'finish');
            $this->timer->clear($room->code);
            $this->patchCtx($room, ['winner' => $winner, 'turn' => null]);

            return [
                Message::broadcast(new Event('team_eliminated', ['teamId' => $teamId])),
                Message::broadcast(new Event('game_over', ['winner' => $winner])),
            ];
        }

        $next = $this->nextAlive($order, $teamId, $eliminated);
        $this->patchCtx($room, ['turn' => $next]);
        $started = $this->timer->start($room->code, $this->seconds($room));

        return [
            Message::broadcast(new Event('team_eliminated', ['teamId' => $teamId, 'turn' => $next])),
            Message::broadcast(new Event(Event::TIMER, $started)),
        ];
    }

    /** @return list<Message> */
    private function restart(Room $room, Actor $actor): array
    {
        $actor->requireHost();

        if ('finished' !== $this->state($room)) {
            throw new IllegalActionException('Gra jeszcze trwa.');
        }

        // Bez patchowania ctx - kolejny start() i tak inicjalizuje wszystko
        // od zera. Wygoda hosta: nie trzeba wracac do sciany gier.
        $this->transition($room, 'restart');

        return [];
    }

    /**
     * Slowo poprawne wchodzi do gry: dopisuje sie do historii, dolicza punkty
     * i sprawdza, czy to juz zwyciestwo. Wspolne dla auto-akceptacji (submit)
     * i werdyktu hosta (rule) - obie sciezki koncza sie tym samym skutkiem.
     *
     * @return array{next: ?string, finished: bool}
     */
    private function applyAcceptedWord(Room $room, string $teamId, string $word, int $points): array
    {
        $used = $this->usedWords($room);
        $used[] = $word;
        $this->patchCtx($room, ['usedWords' => $used, 'lastWord' => $word]);

        $total = $this->scoreboard->add($room->code, $teamId, $points);

        if ($total >= $this->winThreshold($room)) {
            $this->transition($room, 'finish');
            $this->timer->clear($room->code);
            $this->patchCtx($room, ['winner' => $teamId, 'turn' => null]);

            return ['next' => null, 'finished' => true];
        }

        $next = $this->nextAlive($this->order($room), $teamId, $this->eliminated($room));
        $this->patchCtx($room, ['turn' => $next]);

        return ['next' => $next, 'finished' => false];
    }

    /**
     * Punkty za trafienie w TEJ chwili: tyle, ile calych sekund zostalo na
     * zegarze (10s na slowo, odpowiedz po 7s = 3 punkty). Zaokraglenie w gore
     * (ceil), zeby zgadzalo sie z tym, co druzyna WIDZIALA na wyswietlaczu
     * w momencie wysylki (ten sam Math.ceil() liczy tam licznik czasu) -
     * nigdy ponizej zera, gdyby zgloszenie dotarlo dokladnie na granicy.
     */
    private function pointsForRemainingTime(Room $room): int
    {
        return max(0, (int) ceil($this->timer->remaining($room->code) / 1000));
    }

    /**
     * Zwraca komunikat bledu albo null, gdy slowo jest formalnie poprawne
     * (dlugosc, litera startowa, brak powtorki). Nie ocenia, czy slowo
     * naprawde istnieje - tym zajmuje sie WordJudge w submit().
     */
    private function checkWord(Room $room, string $word): ?string
    {
        if (mb_strlen($word) < 2) {
            return 'Słowo musi mieć co najmniej dwie litery.';
        }

        $lastWord = $this->lastWord($room);

        if (null !== $lastWord) {
            $lastChar = mb_strtolower(mb_substr($lastWord, -1));
            $digraph = self::DIGRAPHS[$lastChar] ?? null;
            $fallback = self::NASAL_FALLBACKS[$lastChar] ?? null;
            $first = mb_strtolower(mb_substr($word, 0, 1));

            $startsWithChar = $lastChar === $first || (null !== $fallback && $fallback === $first);
            $startsWithDigraph = null !== $digraph && $digraph === mb_strtolower(mb_substr($word, 0, 2));

            if (!$startsWithChar && !$startsWithDigraph) {
                return sprintf('Słowo musi zaczynać się na „%s”.', mb_strtoupper($digraph ?? $fallback ?? $lastChar));
            }
        }

        $used = array_map(static fn (string $w): string => mb_strtolower($w), $this->usedWords($room));

        if (\in_array(mb_strtolower($word), $used, true)) {
            return self::DUPLICATE_MESSAGE;
        }

        return null;
    }

    /**
     * Nastepna zywa druzyna w stalej kolejnosci, pomijajac wyeliminowane.
     *
     * @param list<string> $order
     * @param list<string> $eliminated
     */
    private function nextAlive(array $order, string $current, array $eliminated): string
    {
        $index = array_search($current, $order, true);
        $index = false === $index ? 0 : $index;
        $n = \count($order);

        for ($i = 1; $i <= $n; ++$i) {
            $candidate = $order[($index + $i) % $n];

            if (!\in_array($candidate, $eliminated, true)) {
                return $candidate;
            }
        }

        // Nie powinno sie zdarzyc - timeout() konczy gre (finish), zanim
        // wszyscy zostana wyeliminowani.
        return $current;
    }

    /** @return array<string, mixed> */
    public function hostView(Room $room): array
    {
        return $this->publicView($room) + ['teams' => $this->teamsView($room)];
    }

    /** @return array<string, mixed> */
    public function playerView(Room $room, Team $team): array
    {
        $turn = $this->turn($room);

        return $this->publicView($room) + [
            'teams' => $this->teamsView($room),
            'myTurn' => $turn === $team->id,
            'isPending' => null !== $this->pendingWord($room) && $turn === $team->id,
        ];
    }

    /** @return array<string, mixed> */
    private function publicView(Room $room): array
    {
        return [
            'state' => $this->state($room),
            'order' => $this->order($room),
            'turn' => $this->turn($room),
            'points' => $this->scoreboard->all($room->code),
            'eliminated' => $this->eliminated($room),
            'usedWords' => $this->usedWords($room),
            'lastWord' => $this->lastWord($room),
            'pendingWord' => $this->pendingWord($room),
            'seconds' => $this->seconds($room),
            'startingPoints' => $this->startingPoints($room),
            'winThreshold' => $this->winThreshold($room),
            'winner' => $this->winner($room),
            'deadline' => $this->timer->deadline($room->code),
            'serverNow' => (int) (microtime(true) * 1000),
        ];
    }

    /** @return list<array{id: string, name: string, color: string}> */
    private function teamsView(Room $room): array
    {
        return array_map(
            static fn (Team $team): array => $team->toPublicArray(),
            $this->rooms->teams($room->code),
        );
    }

    /** @return list<string> */
    private function order(Room $room): array
    {
        $raw = $this->ctx($room)['order'] ?? null;

        return \is_array($raw) ? array_values(array_filter($raw, '\is_string')) : [];
    }

    private function turn(Room $room): ?string
    {
        $raw = $this->ctx($room)['turn'] ?? null;

        return \is_string($raw) ? $raw : null;
    }

    /** @return list<string> */
    private function eliminated(Room $room): array
    {
        $raw = $this->ctx($room)['eliminated'] ?? null;

        return \is_array($raw) ? array_values(array_filter($raw, '\is_string')) : [];
    }

    /** @return list<string> */
    private function usedWords(Room $room): array
    {
        $raw = $this->ctx($room)['usedWords'] ?? null;

        return \is_array($raw) ? array_values(array_filter($raw, '\is_string')) : [];
    }

    private function lastWord(Room $room): ?string
    {
        $raw = $this->ctx($room)['lastWord'] ?? null;

        return \is_string($raw) ? $raw : null;
    }

    private function pendingWord(Room $room): ?string
    {
        $raw = $this->ctx($room)['pendingWord'] ?? null;

        return \is_string($raw) ? $raw : null;
    }

    private function pendingPoints(Room $room): int
    {
        $raw = $this->ctx($room)['pendingPoints'] ?? null;

        return \is_int($raw) ? $raw : 0;
    }

    private function seconds(Room $room): int
    {
        $raw = $this->ctx($room)['seconds'] ?? null;

        return \is_int($raw) ? $raw : self::SECONDS_CHOICES[0];
    }

    private function startingPoints(Room $room): int
    {
        $raw = $this->ctx($room)['startingPoints'] ?? null;

        return \is_int($raw) ? $raw : self::DEFAULT_STARTING_POINTS;
    }

    private function winThreshold(Room $room): int
    {
        $raw = $this->ctx($room)['winThreshold'] ?? null;

        return \is_int($raw) ? $raw : self::DEFAULT_WIN_THRESHOLD;
    }

    private function winner(Room $room): ?string
    {
        $raw = $this->ctx($room)['winner'] ?? null;

        return \is_string($raw) ? $raw : null;
    }
}
