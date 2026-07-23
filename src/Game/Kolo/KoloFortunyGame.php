<?php

declare(strict_types=1);

namespace App\Game\Kolo;

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
use App\Core\Turn\TurnManager;

/**
 * Koło fortuny - gra turowa z odgadywaniem hasla (spec 13.5, 14).
 *
 * Punkty za litery ida najpierw do "banku" biezacego hasla, a na konto druzyny
 * dopiero po rozwiazaniu. Dzieki temu bankrut ma cene, a zgadywanie hasla sens -
 * bez banku kazda trafiona litera bylaby pewnym zyskiem i nikt nie ryzykowalby
 * odpowiedzi.
 */
final class KoloFortunyGame extends AbstractGame
{
    /** Bonus za rozwiazanie hasla - zeby oplacalo sie zgadnac, a nie tylko kręcić. */
    private const SOLVE_BONUS = 500;

    public function __construct(
        GameFsm $fsm,
        GameState $gameState,
        private readonly TurnManager $turns,
        private readonly Scoreboard $scoreboard,
        private readonly RoomManager $rooms,
        private readonly PhraseDeck $deck,
    ) {
        parent::__construct($fsm, $gameState);
    }

    public static function type(): string
    {
        return 'kolo';
    }

    public function name(): string
    {
        return 'Koło fortuny';
    }

    public function definition(): GameDefinition
    {
        return new GameDefinition(
            type: self::type(),
            initialState: 'idle',
            // "next_team" znaczy "czyjas tura, czeka na zakrecenie" - wchodzi
            // sie w niego zarowno po przejeciu tury, jak i po trafionej literze,
            // gdy tura zostaje przy tej samej druzynie. Roznica siedzi
            // w TurnManagerze, nie w stanie: plansza wyglada tak samo.
            states: ['idle', 'spinning', 'landed', 'pick_letter', 'reveal', 'next_team', 'solved'],
            transitions: [
                new Transition('spin', ['idle', 'next_team'], 'spinning'),
                new Transition('land', 'spinning', 'landed'),
                new Transition('ask_letter', 'landed', 'pick_letter'),
                // Bankrut i stop koncza ture juz na kole - bez pytania o litere.
                new Transition('pass', ['landed', 'pick_letter'], 'next_team'),
                new Transition('reveal_letter', 'pick_letter', 'reveal'),
                new Transition('keep_turn', 'reveal', 'next_team'),
                new Transition('pass_turn', 'reveal', 'next_team'),
                // Host oddaje ture, gdy gra juz stoi na granicy tury.
                new Transition('hand_over', 'next_team', 'next_team'),
                new Transition('solve', ['landed', 'pick_letter', 'reveal', 'next_team'], 'solved'),
                new Transition('new_puzzle', ['idle', 'solved'], 'idle'),
            ],
        );
    }

    public function handleAction(Room $room, string $action, array $payload, Actor $actor): array
    {
        return match ($action) {
            'pick_category' => $this->pickCategory($room, $payload, $actor),
            'next_puzzle' => $this->nextPuzzle($room, $actor),
            'spin' => $this->spin($room, $payload, $actor),
            'land' => $this->land($room, $actor),
            'letter' => $this->letter($room, $payload, $actor),
            'solve' => $this->solve($room, $payload, $actor),
            'skip_turn' => $this->skipTurn($room, $actor),
            default => throw new IllegalActionException(sprintf('Koło nie zna akcji "%s".', $action)),
        };
    }

    public function hostView(Room $room): array
    {
        $ctx = $this->ctx($room);

        // Host widzi rozwiazanie - musi wiedziec, czy odpowiedz jest trafiona,
        // zanim serwer ja oceni. Ten widok nie trafia na telefony (spec 7).
        return $this->publicView($room) + [
            'phrase' => $ctx['_phrase'] ?? null,
            'categories' => $this->deck->categoriesFor(self::type()),
        ];
    }

    public function playerView(Room $room, Team $team): array
    {
        return $this->publicView($room) + [
            'myTurn' => $this->turns->isCurrent($room->code, $team->id),
            'myBank' => $this->bank($room)[$team->id] ?? 0,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<Message>
     */
    private function pickCategory(Room $room, array $payload, Actor $actor): array
    {
        $actor->requireHost();

        $slug = $payload['category'] ?? null;

        if (!\is_string($slug) || !$this->deck->hasCategory(self::type(), $slug)) {
            throw new IllegalActionException('Nie ma takiej kategorii.');
        }

        $this->patchCtx($room, [
            'category' => $slug,
            'categoryName' => $this->deck->categoryName(self::type(), $slug),
            'puzzleTotal' => $this->deck->countPhrases(self::type(), $slug),
            'puzzleNumber' => 0,
            '_played' => [],
            '_phrase' => null,
        ]);

        return [Message::broadcast(new Event('category_picked', [
            'categoryName' => $this->deck->categoryName(self::type(), $slug),
            'items' => $this->deck->countPhrases(self::type(), $slug),
        ]))];
    }

    /**
     * Losuje haslo i ustawia kolejnosc druzyn.
     *
     * Kolejnosc ustalamy przy kazdym hasle na nowo, z aktualnej listy druzyn -
     * dzieki temu ktos, kto dolaczyl w trakcie poprzedniego hasla, wchodzi
     * do gry od nastepnego, a nie dopiero po restarcie.
     *
     * @return list<Message>
     */
    private function nextPuzzle(Room $room, Actor $actor): array
    {
        $actor->requireHost();

        $ctx = $this->ctx($room);
        $category = $ctx['category'] ?? null;

        if (!\is_string($category)) {
            throw new IllegalActionException('Najpierw wybierz kategorie.');
        }

        $played = $this->intList($ctx['_played'] ?? null);
        $puzzle = $this->deck->drawNext(self::type(), $category, $played);

        if (null === $puzzle) {
            throw new IllegalActionException('Kategoria sie skonczyla - wybierz inna albo zrestartuj gre.');
        }

        $this->transition($room, 'new_puzzle');

        $played[] = $puzzle->getId();
        $teams = $this->rooms->teams($room->code);

        // Zwyciezca poprzedniego hasla zaczyna nastepne; przy pierwszym hasle
        // po prostu pierwsza druzyna w kolejnosci dolaczenia.
        $starter = $this->turns->start($room->code, $teams);
        $lastWinner = $ctx['lastWinner'] ?? null;

        if (\is_string($lastWinner) && $this->turns->giveTo($room->code, $lastWinner)) {
            $starter = $lastWinner;
        }

        $this->patchCtx($room, [
            '_puzzleId' => $puzzle->getId(),
            '_phrase' => $puzzle->forBoard(),
            '_played' => $played,
            'puzzleNumber' => \count($played),
            'hint' => $puzzle->getHint(),
            // Uwaga: "category" trzyma slug wybranej kategorii i nie wolno go tu
            // nadpisac nazwa - to po nim losujemy kolejne hasla. Nazwa do pokazania
            // siedzi w "categoryName" i ustawia ja pickCategory().
            'mask' => PuzzleBoard::mask($puzzle->forBoard(), []),
            'guessed' => [],
            'wheel' => null,
            'bank' => [],
            'turn' => $starter,
            'lastWinner' => null,
        ]);

        return [Message::broadcast(new Event('puzzle_ready', [
            'number' => \count($played),
            'total' => $ctx['puzzleTotal'] ?? 0,
            'mask' => PuzzleBoard::mask($puzzle->forBoard(), []),
            'hint' => $puzzle->getHint(),
            'categoryName' => $puzzle->getCategory()->getName(),
            'turn' => $starter,
        ]))];
    }

    /**
     * Kreci kolem. Wynik zapada tutaj, na serwerze - ekran hosta dostaje gotowy
     * indeks sektora i tylko na nim zatrzymuje animacje (spec 14).
     *
     * @param array<string, mixed> $payload
     *
     * @return list<Message>
     */
    private function spin(Room $room, array $payload, Actor $actor): array
    {
        $this->requireTurnOrHost($room, $actor);
        $this->transition($room, 'spin');

        // Sila zamachu nie rusza wyniku - ten zapada tutaj, na serwerze
        // (spec 14). Idzie wylacznie do animacji: dluzsze przytrzymanie
        // przycisku to wiecej obrotow i dluzsze dohamowanie na telewizorze.
        // Gdyby wplywala na sektor, telefon decydowalby o wygranej.
        $result = Wheel::spin() + ['power' => $this->power($payload)];

        $this->patchCtx($room, ['wheel' => $result]);

        return [Message::broadcast(new Event('wheel_result', $result))];
    }

    /**
     * Sila zamachu w skali 0-100.
     *
     * @param array<string, mixed> $payload
     */
    private function power(array $payload): int
    {
        $power = $payload['power'] ?? null;

        return \is_numeric($power) ? max(0, min(100, (int) $power)) : 50;
    }

    /**
     * Koniec animacji. Host melduje, ze kolo stanelo, a serwer rozstrzyga,
     * czy druzyna pyta o litere, czy traci ture.
     *
     * @return list<Message>
     */
    private function land(Room $room, Actor $actor): array
    {
        $actor->requireHost();
        $this->transition($room, 'land');

        $wheel = $this->ctx($room)['wheel'] ?? null;
        $value = \is_array($wheel) ? ($wheel['value'] ?? null) : null;

        if (!Wheel::isPenalty(\is_int($value) || \is_string($value) ? $value : 0)) {
            $this->transition($room, 'ask_letter');

            return [];
        }

        $current = $this->turns->current($room->code);

        if (Wheel::BANKRUT === $value && \is_string($current)) {
            $bank = $this->bank($room);
            $bank[$current] = 0;
            $this->patchCtx($room, ['bank' => $bank]);
        }

        return [...$this->passTurn($room, 'pass'), Message::broadcast(new Event('penalty', [
            'kind' => \is_string($value) ? $value : Wheel::STOP,
            'teamId' => $current,
            // Pelny stan bankow, a nie sama kwota jednej druzyny: telefon
            // odtwarza z tego swoja pozycje bez pamietania historii, wiec
            // bankrut zeruje licznik takze temu, kto akurat mial zerwane SSE.
            'banks' => $this->bank($room),
        ]))];
    }

    /**
     * Druzyna podaje litere. Trafiona - punkty do banku i kreci dalej;
     * nietrafiona - tura idzie do nastepnej druzyny.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<Message>
     */
    private function letter(Room $room, array $payload, Actor $actor): array
    {
        $team = $this->requireCurrentTeam($room, $actor);

        $letter = $payload['letter'] ?? null;

        if (!\is_string($letter) || !PuzzleBoard::isValidLetter($letter)) {
            throw new IllegalActionException('To nie jest litera.');
        }

        $letter = PuzzleBoard::normalize($letter);
        $ctx = $this->ctx($room);
        $guessed = $this->stringList($ctx['guessed'] ?? null);

        if (\in_array($letter, $guessed, true)) {
            throw new IllegalActionException(sprintf('Litera "%s" byla juz sprawdzana.', $letter));
        }

        $phrase = $ctx['_phrase'] ?? null;

        if (!\is_string($phrase)) {
            throw new IllegalActionException('Nie ma hasla do zgadywania.');
        }

        $this->transition($room, 'reveal_letter');

        $count = PuzzleBoard::occurrences($phrase, $letter);
        $guessed[] = $letter;

        $wheel = $ctx['wheel'] ?? null;
        $value = \is_array($wheel) && \is_int($wheel['value'] ?? null) ? $wheel['value'] : 0;

        $bank = $this->bank($room);
        $bank[$team->id] = ($bank[$team->id] ?? 0) + $value * $count;

        $mask = PuzzleBoard::mask($phrase, $guessed);

        $this->patchCtx($room, ['guessed' => $guessed, 'bank' => $bank, 'mask' => $mask]);

        $messages = [Message::broadcast(new Event('letter_revealed', [
            'letter' => $letter,
            'count' => $count,
            'mask' => $mask,
            'teamId' => $team->id,
            'bank' => $bank[$team->id],
            'banks' => $bank,
        ]))];

        // Ostatnia litera odslonieta - haslo rozwiazuje sie samo, bez zgadywania.
        if (PuzzleBoard::isSolved($phrase, $guessed)) {
            return [...$messages, ...$this->awardSolve($room, $team, $phrase)];
        }

        if (0 === $count) {
            return [...$messages, ...$this->passTurn($room, 'pass_turn')];
        }

        // Trafiona litera zostawia ture przy druzynie: przechodzimy na granice
        // tury, ale bez ruszania TurnManagera.
        $this->transition($room, 'keep_turn');

        return $messages;
    }

    /**
     * Proba rozwiazania. Trafiona - bank plus bonus na konto; nietrafiona -
     * tura przepada, ale bank zostaje na nastepna kolejke.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<Message>
     */
    private function solve(Room $room, array $payload, Actor $actor): array
    {
        $team = $this->requireCurrentTeam($room, $actor);

        $answer = $payload['phrase'] ?? null;

        if (!\is_string($answer) || '' === trim($answer)) {
            throw new IllegalActionException('Podaj haslo.');
        }

        $phrase = $this->ctx($room)['_phrase'] ?? null;

        if (!\is_string($phrase)) {
            throw new IllegalActionException('Nie ma hasla do zgadywania.');
        }

        if (!PuzzleBoard::matches($phrase, $answer)) {
            return [
                Message::broadcast(new Event('solve_failed', ['teamId' => $team->id, 'teamName' => $team->name])),
                ...$this->passTurn($room),
            ];
        }

        return $this->awardSolve($room, $team, $phrase);
    }

    /** @return list<Message> */
    private function skipTurn(Room $room, Actor $actor): array
    {
        $actor->requireHost();

        return $this->passTurn($room);
    }

    /**
     * Zamyka haslo: bank druzyny plus bonus ida na tablo.
     *
     * @return list<Message>
     */
    private function awardSolve(Room $room, Team $team, string $phrase): array
    {
        $this->transition($room, 'solve');

        $bank = $this->bank($room);
        $won = ($bank[$team->id] ?? 0) + self::SOLVE_BONUS;

        $this->scoreboard->add($room->code, $team->id, $won);

        $this->patchCtx($room, [
            'mask' => $phrase,
            'lastWinner' => $team->id,
            'bank' => [],
        ]);

        return [Message::broadcast(new Event('puzzle_solved', [
            'teamId' => $team->id,
            'teamName' => $team->name,
            'phrase' => $phrase,
            'won' => $won,
            // Rozwiazane haslo zeruje banki wszystkim - kwota przechodzi
            // na tablo, a nowe haslo zaczyna sie od zera.
            'banks' => [],
        ]))];
    }

    /**
     * Oddaje ture nastepnej druzynie.
     *
     * Do granicy tury prowadzi kilka przejsc, zaleznie od tego, gdzie gra
     * wlasnie stoi (pudlo na literze, bankrut, zla odpowiedz, decyzja hosta).
     * Wybieramy pierwsze dozwolone zamiast rozgalezac to w kazdym wywolaniu.
     *
     * @return list<Message>
     */
    private function passTurn(Room $room, string ...$preferred): array
    {
        $candidates = [...$preferred, 'pass_turn', 'pass', 'hand_over'];

        foreach ($candidates as $transition) {
            if ($this->can($room, $transition)) {
                $this->transition($room, $transition);

                $next = $this->turns->next($room->code);
                $this->patchCtx($room, ['turn' => $next]);

                return [];
            }
        }

        throw new IllegalActionException(sprintf('Nie da sie teraz oddac tury (stan "%s").', $this->state($room)));
    }

    private function requireCurrentTeam(Room $room, Actor $actor): Team
    {
        $team = $actor->requireTeam();

        if (!$this->turns->isCurrent($room->code, $team->id)) {
            throw new IllegalActionException('Teraz nie jest wasza kolej.');
        }

        return $team;
    }

    /** Kręcić może drużyna, do której należy tura - albo host w jej imieniu. */
    private function requireTurnOrHost(Room $room, Actor $actor): void
    {
        if ($actor->isHost()) {
            return;
        }

        $this->requireCurrentTeam($room, $actor);
    }

    /** @return array<string, mixed> */
    private function publicView(Room $room): array
    {
        $ctx = $this->ctx($room);

        return [
            'state' => $this->state($room),
            'category' => $ctx['category'] ?? null,
            'categoryName' => $ctx['categoryName'] ?? null,
            'puzzleNumber' => $ctx['puzzleNumber'] ?? 0,
            'puzzleTotal' => $ctx['puzzleTotal'] ?? 0,
            'mask' => $ctx['mask'] ?? null,
            'hint' => $ctx['hint'] ?? null,
            'guessed' => $ctx['guessed'] ?? [],
            'wheel' => $ctx['wheel'] ?? null,
            'bank' => $ctx['bank'] ?? [],
            'turn' => $this->turns->current($room->code),
            'sectors' => Wheel::sectors(),
        ];
    }

    /** @return array<string, int> */
    private function bank(Room $room): array
    {
        $bank = $this->ctx($room)['bank'] ?? [];
        $result = [];

        if (\is_array($bank)) {
            foreach ($bank as $teamId => $points) {
                if (\is_string($teamId) && \is_int($points)) {
                    $result[$teamId] = $points;
                }
            }
        }

        return $result;
    }

    /** @return list<int> */
    private function intList(mixed $value): array
    {
        return \is_array($value) ? array_values(array_filter($value, \is_int(...))) : [];
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        return \is_array($value) ? array_values(array_filter($value, \is_string(...))) : [];
    }
}
