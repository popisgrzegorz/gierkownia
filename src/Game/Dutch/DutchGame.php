<?php

declare(strict_types=1);

namespace App\Game\Dutch;

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

/**
 * Dutch (znany tez jako Cabo) - gra pamieciowa w wariancie startowym.
 *
 * Jeden telefon = jeden gracz. Kazdy ma cztery zakryte karty, na starcie
 * podglada dwie, a potem gra z pamieci: w swojej turze dobiera karte i albo
 * podmienia nia jedna ze swoich (stara idzie na stos), albo ja odrzuca. Kto
 * uwaza, ze ma najnizsza sume, wola "Dutch" - reszta gra jeszcze po jednej
 * turze i odslaniamy karty. Punktacje liczy `Punktacja`.
 *
 * Cala tresc gry to ukryta informacja, wiec siedzi w kontekscie pod kluczami
 * z podkresleniem (`_hands`, `_deck`, `_drawn`), ktorych rdzen nie wypuszcza
 * w rozgloszeniu ani w odpowiedzi HTTP. Telefon dostaje swoje karty wylacznie
 * przez `Message::toTeam` oraz w `playerView` przy wejsciu - nigdy broadcastem.
 *
 * **Uczciwa pamiec:** podejrzane karty `playerView` oddaje tylko w fazie `peek`.
 * Gdy runda rusza, znikaja - telefon nie pamieta ich za gracza, tak jak przy
 * prawdziwych kartach odlozonych zakryciem do gory.
 *
 * Dutch liczy swoj wlasny ranking (`totals` w kontekscie, nizej = lepiej)
 * i **nie dopisuje** do wspolnego tabla wieczoru: tamto sumuje "wiecej = lepiej",
 * a tu wygrywa najnizszy wynik - zmieszanie obu psuloby jedno i drugie.
 */
final class DutchGame extends AbstractGame
{
    /** Karty na gracza (klasyczny kwadrat 2x2). */
    private const HAND_SIZE = 4;

    /** Ile wlasnych kart wolno podejrzec na starcie. */
    private const PEEK_LIMIT = 2;

    /** Kara punktowa za bledne zawolanie "Dutch". */
    private const PENALTY = 5;

    /** Dutch to gra dla co najmniej dwoch osob. */
    private const MIN_PLAYERS = 2;

    /**
     * Puste pole po dorzuconej karcie. Reka nie jest juz stala (dorzucenie
     * ujmuje karte, kara dokłada), ale pola trzymamy w miejscu - inaczej pamiec
     * o "drugiej karcie" rozsypalaby sie przy kazdym ubytku. Wartownik zamiast
     * null, zeby reka pozostala lista intow w kontekscie.
     */
    private const EMPTY = -1;

    public function __construct(
        GameFsm $fsm,
        GameState $gameState,
        private readonly RoomManager $rooms,
    ) {
        parent::__construct($fsm, $gameState);
    }

    public static function type(): string
    {
        return 'dutch';
    }

    public function name(): string
    {
        return 'Dutch';
    }

    public function definition(): GameDefinition
    {
        return new GameDefinition(
            type: self::type(),
            initialState: 'idle',
            states: ['idle', 'peek', 'playing', 'final', 'reveal'],
            transitions: [
                new Transition('deal', 'idle', 'peek'),
                new Transition('begin', 'peek', 'playing'),
                // "Dutch": od tej chwili reszta gra jeszcze po jednej turze.
                new Transition('call', 'playing', 'final'),
                // Koniec rundy: recznie przez hosta albo automatycznie po
                // ostatniej turze rundy koncowej lub gdy skonczy sie talia.
                new Transition('finish', ['playing', 'final'], 'reveal'),
                new Transition('next_round', 'reveal', 'idle'),
            ],
        );
    }

    public function handleAction(Room $room, string $action, array $payload, Actor $actor): array
    {
        return match ($action) {
            'deal' => $this->deal($room, $actor),
            'peek' => $this->peek($room, $payload, $actor),
            'begin' => $this->begin($room, $actor),
            'draw' => $this->draw($room, $actor),
            'swap' => $this->swap($room, $payload, $actor),
            'discard' => $this->discard($room, $actor),
            'snap' => $this->snap($room, $payload, $actor),
            'power' => $this->usePower($room, $actor),
            'power_peek' => $this->peekPower($room, $payload, $actor),
            'power_swap' => $this->swapCards($room, $payload, $actor),
            'call' => $this->call($room, $actor),
            'finish' => $this->finishByHost($room, $actor),
            'next_round' => $this->nextRound($room, $actor),
            default => throw new IllegalActionException(sprintf('Dutch nie zna akcji "%s".', $action)),
        };
    }

    /**
     * Rozdanie: tasujemy talie, dajemy kazdemu cztery karty i wchodzimy w faze
     * podgladu. Wolno tylko z lobby gry (`idle`) i przy komplecie graczy.
     *
     * @return list<Message>
     */
    private function deal(Room $room, Actor $actor): array
    {
        $actor->requireHost();

        if ('idle' !== $this->state($room)) {
            throw new IllegalActionException('Rozdajemy między rundami.');
        }

        $teams = $this->rooms->teams($room->code);

        if (\count($teams) < self::MIN_PLAYERS) {
            throw new IllegalActionException('Do Dutcha potrzeba co najmniej dwóch graczy.');
        }

        $deck = Karty::deck();
        shuffle($deck);

        $hands = [];
        foreach ($teams as $team) {
            $hands[$team->id] = array_splice($deck, 0, self::HAND_SIZE);
        }

        $order = array_map(static fn (Team $team): string => $team->id, $teams);
        $round = $this->round($room) + 1;

        $this->transition($room, 'deal');

        $this->patchCtx($room, [
            'round' => $round,
            'order' => $order,
            'turn' => $order[0],
            'calledBy' => null,
            'revealed' => null,
            'scores' => null,
            '_deck' => $deck,
            '_hands' => $hands,
            '_discard' => [],
            '_drawn' => null,
            '_drawnBy' => null,
            '_peeked' => [],
            '_finalRemaining' => 0,
            '_power' => null,
        ]);

        return [Message::broadcast(new Event('dealt', [
            'round' => $round,
            'order' => $order,
            'turn' => $order[0],
            'deckCount' => \count($deck),
        ]))];
    }

    /**
     * Podgląd jednej własnej karty na starcie. Wolno obejrzeć dwie; każda wraca
     * tylko do tego telefonu, nigdy broadcastem.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<Message>
     */
    private function peek(Room $room, array $payload, Actor $actor): array
    {
        $team = $actor->requireTeam();

        if ('peek' !== $this->state($room)) {
            throw new IllegalActionException('Podglądać można tylko na początku rundy.');
        }

        $slot = \is_int($payload['slot'] ?? null) ? $payload['slot'] : -1;

        if ($slot < 0 || $slot >= self::HAND_SIZE) {
            throw new IllegalActionException('Nie ma takiej karty.');
        }

        $peeked = $this->peeked($room);
        $mine = $peeked[$team->id] ?? [];

        if (!\in_array($slot, $mine, true)) {
            if (\count($mine) >= self::PEEK_LIMIT) {
                throw new IllegalActionException('Możesz podejrzeć tylko dwie karty.');
            }

            $mine[] = $slot;
            $peeked[$team->id] = $mine;
            $this->patchCtx($room, ['_peeked' => $peeked]);
        }

        $card = $this->handsOf($room)[$team->id][$slot] ?? null;

        if (null === $card) {
            throw new IllegalActionException('Tej karty nie ma na stole.');
        }

        return [
            // Wartość karty tylko do gracza...
            Message::toTeam($team->id, new Event('peeked', [
                'slot' => $slot,
                'card' => $this->cardInfo($card),
            ])),
            // ...a sam fakt (bez wartości) do wszystkich, żeby TV wiedziało,
            // kto ile kart już podejrzał.
            Message::broadcast(new Event('peek_progress', [
                'teamId' => $team->id,
                'peeked' => \count($mine),
                'total' => self::PEEK_LIMIT,
            ])),
        ];
    }

    /**
     * Koniec podglądu - rusza pierwsza tura.
     *
     * @return list<Message>
     */
    private function begin(Room $room, Actor $actor): array
    {
        $actor->requireHost();
        $this->transition($room, 'begin');

        // Samą zmianę fazy ogłasza rdzeń; telefony na jej podstawie chowają
        // podejrzane karty i przechodzą na pamięć.
        return [];
    }

    /**
     * Dobranie karty z talii. Trafia wyłącznie do dobierającego; reszta widzi
     * tylko, że ktoś dobiera i ile kart zostało w talii.
     *
     * @return list<Message>
     */
    private function draw(Room $room, Actor $actor): array
    {
        $team = $actor->requireTeam();
        $this->requireMyTurn($room, $team);
        $this->requireNoPendingPower($room);

        if (null !== $this->drawn($room)) {
            throw new IllegalActionException('Masz już dobraną kartę - podmień ją albo odrzuć.');
        }

        $deck = $this->deck($room);

        if ([] === $deck) {
            $deck = $this->reshuffle($room);
        }

        // Talia i stos wyczerpane naraz - nie ma z czego dobierać, więc runda
        // kończy się tu, zamiast zawisnąć na pustej talii.
        if ([] === $deck) {
            return $this->finishRound($room);
        }

        $card = array_shift($deck);

        $this->patchCtx($room, [
            '_deck' => $deck,
            '_drawn' => $card,
            '_drawnBy' => $team->id,
        ]);

        return [
            // Moc karty leci razem z nią - inaczej telefon nie wie, że dobrał
            // damę, i nie pokaże przycisku podglądu (widok po odświeżeniu liczy
            // ją sam, ale samo dobranie na żywo już nie).
            Message::toTeam($team->id, new Event('drawn', [
                'card' => $this->cardInfo($card),
                'power' => Karty::power($card),
            ])),
            Message::broadcast(new Event('drew', ['teamId' => $team->id, 'deckCount' => \count($deck)])),
        ];
    }

    /**
     * Podmiana dobranej karty za jedną ze swoich; stara ląduje odkryta na stosie.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<Message>
     */
    private function swap(Room $room, array $payload, Actor $actor): array
    {
        $team = $actor->requireTeam();
        $this->requireMyTurn($room, $team);
        $this->requireNoPendingPower($room);

        $drawn = $this->drawn($room);

        if (null === $drawn) {
            throw new IllegalActionException('Najpierw dobierz kartę.');
        }

        $slot = \is_int($payload['slot'] ?? null) ? $payload['slot'] : -1;
        $hands = $this->handsOf($room);
        $old = $hands[$team->id][$slot] ?? null;

        if (null === $old || self::EMPTY === $old) {
            throw new IllegalActionException('Tej karty nie ma na stole.');
        }

        $hands[$team->id][$slot] = $drawn;
        $discard = $this->discardPile($room);
        $discard[] = $old;

        $this->patchCtx($room, [
            '_hands' => $hands,
            '_discard' => $discard,
            '_drawn' => null,
            '_drawnBy' => null,
        ]);

        $messages = [Message::broadcast(new Event('swapped', [
            'teamId' => $team->id,
            'slot' => $slot,
            'discardTop' => $this->cardInfo($old),
            'discardCount' => \count($discard),
        ]))];

        return array_merge($messages, $this->endTurn($room));
    }

    /**
     * Odrzucenie dobranej karty bez podmiany. W wariancie startowym nie ma mocy
     * specjalnych, więc odrzucenie po prostu kończy turę.
     *
     * @return list<Message>
     */
    private function discard(Room $room, Actor $actor): array
    {
        $team = $actor->requireTeam();
        $this->requireMyTurn($room, $team);
        $this->requireNoPendingPower($room);

        $drawn = $this->drawn($room);

        if (null === $drawn) {
            throw new IllegalActionException('Najpierw dobierz kartę.');
        }

        $discard = $this->discardPile($room);
        $discard[] = $drawn;

        $this->patchCtx($room, [
            '_discard' => $discard,
            '_drawn' => null,
            '_drawnBy' => null,
        ]);

        $messages = [Message::broadcast(new Event('discarded', [
            'teamId' => $team->id,
            'discardTop' => $this->cardInfo($drawn),
            'discardTail' => array_map($this->cardInfo(...), \array_slice($discard, -2)),
            'discardCount' => \count($discard),
        ]))];

        return array_merge($messages, $this->endTurn($room));
    }

    /**
     * Dorzucenie pasującej karty na stos - refleks, dostępny dla KAŻDEGO w każdej
     * chwili gry (nie tylko w swojej turze). Liczy się figura wierzchu stosu,
     * nie kolor. Trafienie ujmuje kartę z ręki (mniej kart = mniej punktów),
     * pudło zostawia ją i dokłada karną z talii. Tury to nie rusza.
     *
     * O tym, co jest na wierzchu, rozstrzyga serwer w chwili dorzucenia - Redis
     * szereguje żądania, więc dwa dorzucenia nie wejdą sobie w drogę.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<Message>
     */
    private function snap(Room $room, array $payload, Actor $actor): array
    {
        $team = $actor->requireTeam();

        if (!\in_array($this->state($room), ['playing', 'final'], true)) {
            throw new IllegalActionException('Teraz nie dorzucasz kart.');
        }

        $discard = $this->discardPile($room);

        if ([] === $discard) {
            throw new IllegalActionException('Na stosie jeszcze nic nie leży.');
        }

        $topRank = Karty::rank($discard[\count($discard) - 1]);

        $hands = $this->handsOf($room);
        $mine = $hands[$team->id] ?? [];
        $slot = \is_int($payload['slot'] ?? null) ? $payload['slot'] : -1;

        if (!isset($mine[$slot]) || self::EMPTY === $mine[$slot]) {
            throw new IllegalActionException('Nie ma takiej karty.');
        }

        $card = $mine[$slot];

        if (Karty::rank($card) === $topRank) {
            // Trafiona figura: karta ląduje na stosie, jej pole zostaje puste.
            $mine[$slot] = self::EMPTY;
            $discard[] = $card;
            $hands[$team->id] = $mine;
            $this->patchCtx($room, ['_hands' => $hands, '_discard' => $discard]);

            return [Message::broadcast(new Event('snapped', [
                'teamId' => $team->id,
                'slot' => $slot,
                'discardTop' => $this->cardInfo($card),
                'discardTail' => array_map($this->cardInfo(...), \array_slice($discard, -2)),
                'discardCount' => \count($discard),
                'hands' => $this->handShapes($room, $hands),
            ]))];
        }

        // Pudło: figura się nie zgadza. Karta zostaje w ręce, ale zapłatą za
        // pomyłkę jest odsłonięcie jej wszystkim (kara pamięciowa) ORAZ karna
        // karta z talii, dobrana w ciemno.
        $penalty = $this->drawOne($room);

        if (null !== $penalty) {
            $mine[] = $penalty;
            $hands[$team->id] = $mine;
            $this->patchCtx($room, ['_hands' => $hands]);
        }

        return [Message::broadcast(new Event('snap_failed', [
            'teamId' => $team->id,
            'slot' => $slot,
            // Kartę, którą chciał wyrzucić, widzą wszyscy - to sedno kary.
            'card' => $this->cardInfo($card),
            'deckCount' => \count($this->deck($room)),
            'hands' => $this->handShapes($room, $hands),
        ]))];
    }

    /**
     * Karta z mocą (7,8 = podejrzyj swoją, 9,10 = podejrzyj cudzą): gracz zrzuca
     * ją zamiast podmiany i wskazuje kartę do podejrzenia. Tura czeka na wskazanie.
     *
     * @return list<Message>
     */
    private function usePower(Room $room, Actor $actor): array
    {
        $team = $actor->requireTeam();
        $this->requireMyTurn($room, $team);
        $this->requireNoPendingPower($room);

        $drawn = $this->drawn($room);

        if (null === $drawn) {
            throw new IllegalActionException('Najpierw dobierz kartę.');
        }

        $kind = Karty::power($drawn);

        if (null === $kind) {
            throw new IllegalActionException('Ta karta nie ma mocy - podmień ją albo wyrzuć.');
        }

        $discard = $this->discardPile($room);
        $discard[] = $drawn;

        // Figura idzie na stos, a tura czeka na wskazanie celu mocy.
        $this->patchCtx($room, [
            '_discard' => $discard,
            '_drawn' => null,
            '_drawnBy' => null,
            '_power' => $kind,
        ]);

        return [
            Message::broadcast(new Event('discarded', [
                'teamId' => $team->id,
                'discardTop' => $this->cardInfo($drawn),
                'discardTail' => array_map($this->cardInfo(...), \array_slice($discard, -2)),
                'discardCount' => \count($discard),
                'power' => $kind,
            ])),
            Message::toTeam($team->id, new Event('power', ['kind' => $kind])),
        ];
    }

    /**
     * Wskazanie karty do podejrzenia. Siódemka/ósemka odsłania tylko własną
     * kartę, dziewiątka/dziesiątka - tylko cudzą. Odsłonięcie trafia wyłącznie
     * do podglądającego, a tura po nim się kończy.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<Message>
     */
    private function peekPower(Room $room, array $payload, Actor $actor): array
    {
        $team = $actor->requireTeam();
        $this->requireMyTurn($room, $team);

        $kind = $this->pendingPower($room);

        if ('peek_own' !== $kind && 'peek_other' !== $kind) {
            throw new IllegalActionException('Teraz nie podglądasz.');
        }

        $targetId = \is_string($payload['teamId'] ?? null) ? $payload['teamId'] : '';
        $slot = \is_int($payload['slot'] ?? null) ? $payload['slot'] : -1;
        $owner = $this->rooms->team($room->code, $targetId);

        if (null === $owner) {
            throw new IllegalActionException('Nie ma takiej karty.');
        }

        if ('peek_own' === $kind && $targetId !== $team->id) {
            throw new IllegalActionException('Tą kartą podglądasz tylko własną kartę.');
        }

        if ('peek_other' === $kind && $targetId === $team->id) {
            throw new IllegalActionException('Tą kartą podglądasz kartę przeciwnika.');
        }

        $card = $this->handsOf($room)[$targetId][$slot] ?? null;

        if (null === $card || self::EMPTY === $card) {
            throw new IllegalActionException('Tej karty nie ma na stole.');
        }

        $this->patchCtx($room, ['_power' => null]);

        $messages = [Message::toTeam($team->id, new Event('power_seen', [
            'ownerId' => $targetId,
            'ownerName' => $owner->name,
            'slot' => $slot,
            'card' => $this->cardInfo($card),
        ]))];

        return array_merge($messages, $this->endTurn($room));
    }

    /**
     * Walet: gracz wskazuje DWÓCH graczy, a serwer LOSOWO zamienia im po jednej
     * karcie (zasada domowa). Zamiana jest w ciemno - nikt nie widzi wartości,
     * na wierzch idzie tylko to, czyje pola się przełożyły. Po niej tura się kończy.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<Message>
     */
    private function swapCards(Room $room, array $payload, Actor $actor): array
    {
        $team = $actor->requireTeam();
        $this->requireMyTurn($room, $team);

        if ('swap' !== $this->pendingPower($room)) {
            throw new IllegalActionException('Teraz nie zamieniasz waletem.');
        }

        $idA = \is_string($payload['a'] ?? null) ? $payload['a'] : '';
        $idB = \is_string($payload['b'] ?? null) ? $payload['b'] : '';

        if ($idA === $idB) {
            throw new IllegalActionException('Wskaż dwóch różnych graczy.');
        }

        if (null === $this->rooms->team($room->code, $idA) || null === $this->rooms->team($room->code, $idB)) {
            throw new IllegalActionException('Nie ma takiego gracza.');
        }

        $hands = $this->handsOf($room);
        $slotsA = $this->filledSlots($hands[$idA] ?? []);
        $slotsB = $this->filledSlots($hands[$idB] ?? []);

        if ([] === $slotsA || [] === $slotsB) {
            throw new IllegalActionException('Wybrany gracz nie ma już kart do zamiany.');
        }

        // Losowo po jednej karcie od każdego z dwóch wskazanych graczy.
        $slotA = $slotsA[random_int(0, \count($slotsA) - 1)];
        $slotB = $slotsB[random_int(0, \count($slotsB) - 1)];

        $cardA = $hands[$idA][$slotA];
        $hands[$idA][$slotA] = $hands[$idB][$slotB];
        $hands[$idB][$slotB] = $cardA;

        $this->patchCtx($room, ['_hands' => $hands, '_power' => null]);

        // W rozgłoszeniu tylko które pola się przełożyły - wartości nie wychodzą.
        $messages = [Message::broadcast(new Event('blind_swapped', [
            'teamId' => $team->id,
            'a' => ['teamId' => $idA, 'slot' => $slotA],
            'b' => ['teamId' => $idB, 'slot' => $slotB],
        ]))];

        return array_merge($messages, $this->endTurn($room));
    }

    /**
     * Numery pól z kartą (bez luk po dorzuceniu).
     *
     * @param list<int> $hand
     *
     * @return list<int>
     */
    private function filledSlots(array $hand): array
    {
        $out = [];

        foreach ($hand as $slot => $card) {
            if (self::EMPTY !== $card) {
                $out[] = $slot;
            }
        }

        return $out;
    }

    /**
     * Zawołanie "Dutch" - na początku swojej tury, zanim się dobierze. Tura
     * wołającego kończy się od razu, a reszta dostaje jeszcze po jednym ruchu.
     *
     * @return list<Message>
     */
    private function call(Room $room, Actor $actor): array
    {
        $team = $actor->requireTeam();

        if ('playing' !== $this->state($room)) {
            throw new IllegalActionException('„Dutch” wołasz na początku swojej tury.');
        }

        $this->requireMyTurn($room, $team);
        $this->requireNoPendingPower($room);

        if (null !== $this->drawn($room)) {
            throw new IllegalActionException('„Dutch” wołasz, zanim dobierzesz kartę.');
        }

        $order = $this->order($room);
        $next = $this->nextOf($order, $team->id);

        $this->transition($room, 'call');

        $this->patchCtx($room, [
            'calledBy' => $team->id,
            '_finalRemaining' => \count($order) - 1,
            'turn' => $next,
        ]);

        return [
            Message::broadcast(new Event('called', ['teamId' => $team->id])),
            Message::broadcast(new Event('turn', ['teamId' => $next])),
        ];
    }

    /**
     * Ręczne zakończenie rundy przez hosta (np. gdy chce podliczyć wcześniej).
     *
     * @return list<Message>
     */
    private function finishByHost(Room $room, Actor $actor): array
    {
        $actor->requireHost();

        return $this->finishRound($room);
    }

    /** @return list<Message> */
    private function nextRound(Room $room, Actor $actor): array
    {
        $actor->requireHost();
        $this->transition($room, 'next_round');

        // Nowa kartka: czyścimy rundę, zostawiamy narastający ranking (totals).
        $this->patchCtx($room, [
            'turn' => null,
            'calledBy' => null,
            'revealed' => null,
            'scores' => null,
            '_deck' => [],
            '_hands' => [],
            '_discard' => [],
            '_drawn' => null,
            '_drawnBy' => null,
            '_peeked' => [],
            '_finalRemaining' => 0,
            '_power' => null,
        ]);

        return [Message::broadcast(new Event('round_reset', []))];
    }

    /**
     * Przekazuje turę dalej. W rundzie końcowej odlicza pozostałe ruchy i sama
     * kończy rundę, gdy każdy dograł swoją ostatnią turę.
     *
     * @return list<Message>
     */
    private function endTurn(Room $room): array
    {
        $order = $this->order($room);
        $current = $this->turn($room) ?? ($order[0] ?? '');

        if ('final' === $this->state($room)) {
            $remaining = $this->finalRemaining($room) - 1;

            if ($remaining <= 0) {
                return $this->finishRound($room);
            }

            $next = $this->nextOf($order, $current);
            $this->patchCtx($room, ['_finalRemaining' => $remaining, 'turn' => $next]);

            return [Message::broadcast(new Event('turn', ['teamId' => $next]))];
        }

        $next = $this->nextOf($order, $current);
        $this->patchCtx($room, ['turn' => $next]);

        return [Message::broadcast(new Event('turn', ['teamId' => $next]))];
    }

    /**
     * Odsłania wszystkie karty, liczy rundę i dokłada wynik do rankingu.
     *
     * @return list<Message>
     */
    private function finishRound(Room $room): array
    {
        $this->transition($room, 'finish');

        $hands = $this->handsOf($room);
        $result = Punktacja::score($hands, $this->calledBy($room), self::PENALTY);

        $totals = $this->totals($room);
        foreach ($result['sums'] as $teamId => $sum) {
            $totals[$teamId] = ($totals[$teamId] ?? 0) + $sum;
        }

        $revealed = [];
        foreach ($hands as $teamId => $cards) {
            // Puste pole (dorzucona karta) pokazujemy jako lukę, nie kartę.
            $revealed[$teamId] = array_map(
                fn (int $card): ?array => self::EMPTY === $card ? null : $this->cardInfo($card),
                $cards,
            );
        }

        $this->patchCtx($room, [
            'revealed' => $revealed,
            'scores' => $result,
            'totals' => $totals,
            '_drawn' => null,
            '_drawnBy' => null,
        ]);

        return [Message::broadcast(new Event('revealed', [
            'hands' => $revealed,
            'scores' => $result,
            'totals' => $totals,
        ]))];
    }

    public function hostView(Room $room): array
    {
        // Ile kart podejrzała każda drużyna - żeby host wiedział, na kogo czekać
        // przed startem. Same liczby, nigdy treść.
        $peekProgress = [];
        foreach ($this->peeked($room) as $teamId => $slots) {
            $peekProgress[$teamId] = \count($slots);
        }

        return $this->publicView($room) + [
            'teams' => array_map(
                static fn (Team $team): array => ['id' => $team->id, 'name' => $team->name, 'color' => $team->color],
                $this->rooms->teams($room->code),
            ),
            'peekProgress' => $peekProgress,
            'canDeal' => \count($this->rooms->teams($room->code)) >= self::MIN_PLAYERS,
            'peekLimit' => self::PEEK_LIMIT,
        ];
    }

    public function playerView(Room $room, Team $team): array
    {
        $state = $this->state($room);
        $view = $this->publicView($room);

        // Uczciwa pamięć: podejrzane karty oddajemy tylko w fazie podglądu.
        // Gdy runda rusza, znikają - dalej gra się z głowy.
        $peekedCards = [];
        if ('peek' === $state) {
            $hand = $this->handsOf($room)[$team->id] ?? [];
            foreach ($this->peeked($room)[$team->id] ?? [] as $slot) {
                if (isset($hand[$slot])) {
                    $peekedCards[$slot] = $this->cardInfo($hand[$slot]);
                }
            }
        }

        // Dobrana karta trafia tylko do tego, kto ją trzyma.
        $drawn = null;
        if (\in_array($state, ['playing', 'final'], true)
            && $this->turn($room) === $team->id
            && $team->id === $this->drawnBy($room)
            && null !== $this->drawn($room)) {
            $drawn = $this->cardInfo((int) $this->drawn($room));
        }

        $drawnId = $this->drawn($room);

        return $view + [
            'myId' => $team->id,
            'myTurn' => $this->turn($room) === $team->id,
            'peekLimit' => self::PEEK_LIMIT,
            'peekedCards' => $peekedCards,
            'drawn' => $drawn,
            // Czy dobrana karta to dama (można zagrać jej moc) i czy właśnie
            // trwa wskazywanie karty do podejrzenia - jedno i drugie tylko dla
            // gracza w swojej turze.
            // Moc dobranej karty (peek_own / peek_other / null) - telefon pokaże
            // odpowiedni przycisk. I jaka moc właśnie czeka na wskazanie celu.
            'drawnPower' => null !== $drawn && null !== $drawnId ? Karty::power($drawnId) : null,
            'power' => $this->turn($room) === $team->id ? $this->pendingPower($room) : null,
            // Kształt własnej ręki - telefon wie, gdzie ma karty (do dorzucania,
            // podmiany, mocy), ale nadal nie zna ich wartości.
            'myHand' => $this->handShapes($room)[$team->id] ?? [],
        ];
    }

    /** @return array<string, mixed> */
    private function publicView(Room $room): array
    {
        $state = $this->state($room);
        $discard = $this->discardPile($room);
        $top = [] === $discard ? null : $this->cardInfo($discard[\count($discard) - 1]);

        // Ogon stosu: dwie wierzchnie karty (starsza pierwsza, wierzchnia
        // ostatnia). Na stole widać obie, żeby było czytelnie, co ostatnio padło.
        $tail = array_map($this->cardInfo(...), \array_slice($discard, -2));

        return [
            'state' => $state,
            'round' => $this->round($room),
            'turn' => $this->turn($room),
            'order' => $this->order($room),
            'calledBy' => $this->calledBy($room),
            'handSize' => self::HAND_SIZE,
            'deckCount' => \count($this->deck($room)),
            'discardTop' => $top,
            'discardTail' => $tail,
            'discardCount' => \count($discard),
            // Kształty rąk (ile kart, gdzie puste pola) - jawne, bez wartości.
            'hands' => $this->handShapes($room),
            'players' => array_map(
                static fn (Team $team): array => ['id' => $team->id, 'name' => $team->name, 'color' => $team->color],
                $this->rooms->teams($room->code),
            ),
            'revealed' => 'reveal' === $state ? $this->revealed($room) : null,
            'scores' => 'reveal' === $state ? $this->scores($room) : null,
            'totals' => $this->totals($room),
        ];
    }

    private function requireMyTurn(Room $room, Team $team): void
    {
        if (!\in_array($this->state($room), ['playing', 'final'], true)) {
            throw new IllegalActionException('Teraz nie gramy kartami.');
        }

        if ($this->turn($room) !== $team->id) {
            throw new IllegalActionException('To nie twoja tura.');
        }
    }

    /** Dopóki wisi moc damy, żadna inna akcja tury nie może wejść. */
    private function requireNoPendingPower(Room $room): void
    {
        if (null !== $this->pendingPower($room)) {
            throw new IllegalActionException('Najpierw wskaż kartę do podejrzenia (dama).');
        }
    }

    private function pendingPower(Room $room): ?string
    {
        $raw = $this->ctx($room)['_power'] ?? null;

        return \is_string($raw) ? $raw : null;
    }

    /**
     * Talia pusta: przetasowujemy stos odrzuconych bez wierzchniej karty i on
     * zostaje nową talią. Zwraca nową talię (pustą, gdy nie ma z czego brać).
     *
     * @return list<int>
     */
    private function reshuffle(Room $room): array
    {
        $discard = $this->discardPile($room);

        if (\count($discard) <= 1) {
            return [];
        }

        $top = array_pop($discard);
        shuffle($discard);

        $this->patchCtx($room, ['_deck' => $discard, '_discard' => [$top]]);

        return $discard;
    }

    /** Dobiera jedną kartę z talii (z przetasowaniem stosu, gdy pusta). */
    private function drawOne(Room $room): ?int
    {
        $deck = $this->deck($room);

        if ([] === $deck) {
            $deck = $this->reshuffle($room);
        }

        if ([] === $deck) {
            return null;
        }

        $card = array_shift($deck);
        $this->patchCtx($room, ['_deck' => $deck]);

        return $card;
    }

    /**
     * Struktura rąk dla ekranów: teamId => lista bool (true = karta, false =
     * puste pole po dorzuceniu). Same kształty, bez wartości - te są tajne do
     * końca rundy.
     *
     * @param array<string, list<int>>|null $hands
     *
     * @return array<string, list<bool>>
     */
    private function handShapes(Room $room, ?array $hands = null): array
    {
        $hands ??= $this->handsOf($room);
        $out = [];

        foreach ($this->rooms->teams($room->code) as $team) {
            $out[$team->id] = array_map(
                static fn (int $card): bool => self::EMPTY !== $card,
                $hands[$team->id] ?? [],
            );
        }

        return $out;
    }

    /**
     * @param list<string> $order
     */
    private function nextOf(array $order, string $current): string
    {
        $index = array_search($current, $order, true);

        if (false === $index) {
            return $order[0] ?? $current;
        }

        return $order[($index + 1) % \count($order)];
    }

    /** @return array{value: int, label: string, red: bool} */
    private function cardInfo(int $id): array
    {
        return [
            'value' => Karty::value($id),
            'label' => Karty::label($id),
            'red' => Karty::isRed($id),
        ];
    }

    /** @return list<int> */
    private function deck(Room $room): array
    {
        return $this->intList($this->ctx($room)['_deck'] ?? null);
    }

    /** @return list<int> */
    private function discardPile(Room $room): array
    {
        return $this->intList($this->ctx($room)['_discard'] ?? null);
    }

    /** @return array<string, list<int>> */
    private function handsOf(Room $room): array
    {
        $raw = $this->ctx($room)['_hands'] ?? null;
        $out = [];

        if (!\is_array($raw)) {
            return $out;
        }

        foreach ($raw as $teamId => $cards) {
            if (\is_string($teamId)) {
                $out[$teamId] = $this->intList($cards);
            }
        }

        return $out;
    }

    /** @return array<string, list<int>> */
    private function peeked(Room $room): array
    {
        $raw = $this->ctx($room)['_peeked'] ?? null;
        $out = [];

        if (!\is_array($raw)) {
            return $out;
        }

        foreach ($raw as $teamId => $slots) {
            if (\is_string($teamId)) {
                $out[$teamId] = $this->intList($slots);
            }
        }

        return $out;
    }

    /** @return array<string, int> */
    private function totals(Room $room): array
    {
        $raw = $this->ctx($room)['totals'] ?? null;
        $out = [];

        if (!\is_array($raw)) {
            return $out;
        }

        foreach ($raw as $teamId => $value) {
            if (\is_string($teamId) && \is_int($value)) {
                $out[$teamId] = $value;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function order(Room $room): array
    {
        $raw = $this->ctx($room)['order'] ?? null;

        return \is_array($raw) ? array_values(array_filter($raw, '\is_string')) : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function revealed(Room $room): ?array
    {
        return $this->stringKeyed($this->ctx($room)['revealed'] ?? null);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function scores(Room $room): ?array
    {
        return $this->stringKeyed($this->ctx($room)['scores'] ?? null);
    }

    /**
     * Odtwarza tablicę o kluczach stringowych z workowego kontekstu - dla pól,
     * które oddajemy wprost do widoku (odsłonięte ręce, wynik rundy).
     *
     * @return array<string, mixed>|null
     */
    private function stringKeyed(mixed $raw): ?array
    {
        if (!\is_array($raw)) {
            return null;
        }

        $out = [];
        foreach ($raw as $key => $value) {
            if (\is_string($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function turn(Room $room): ?string
    {
        $raw = $this->ctx($room)['turn'] ?? null;

        return \is_string($raw) ? $raw : null;
    }

    private function calledBy(Room $room): ?string
    {
        $raw = $this->ctx($room)['calledBy'] ?? null;

        return \is_string($raw) ? $raw : null;
    }

    private function drawnBy(Room $room): ?string
    {
        $raw = $this->ctx($room)['_drawnBy'] ?? null;

        return \is_string($raw) ? $raw : null;
    }

    private function drawn(Room $room): ?int
    {
        $raw = $this->ctx($room)['_drawn'] ?? null;

        return \is_int($raw) ? $raw : null;
    }

    private function round(Room $room): int
    {
        $raw = $this->ctx($room)['round'] ?? null;

        return \is_int($raw) ? $raw : 0;
    }

    private function finalRemaining(Room $room): int
    {
        $raw = $this->ctx($room)['_finalRemaining'] ?? null;

        return \is_int($raw) ? $raw : 0;
    }

    /**
     * @return list<int>
     */
    private function intList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (\is_int($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }
}
