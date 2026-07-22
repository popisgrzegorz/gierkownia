<?php

declare(strict_types=1);

namespace App\Game\Melodia;

use App\Core\Buzzer\BuzzerArbiter;
use App\Core\Game\AbstractGame;
use App\Core\Game\Actor;
use App\Core\Game\GameDefinition;
use App\Core\Game\GameState;
use App\Core\Game\IllegalActionException;
use App\Core\Game\Transition;
use App\Core\Game\Fsm\GameFsm;
use App\Core\Realtime\Event;
use App\Core\Realtime\Message;
use App\Core\Room\Room;
use App\Core\Room\Team;
use App\Core\Score\Scoreboard;
use App\Entity\Track;

/**
 * "Jaka to melodia" - rozpoznawanie utworow z buzzerem (spec 13.3, 14).
 *
 * Modul jest bezstanowy: caly przebieg rundy zyje w Redisie. Dzwiek obsluguje
 * wylacznie ekran hosta - serwer nie wie, co gra, i wiedziec nie musi.
 */
final class MelodiaGame extends AbstractGame
{
    public function __construct(
        GameFsm $fsm,
        GameState $gameState,
        private readonly BuzzerArbiter $buzzer,
        private readonly Scoreboard $scoreboard,
        private readonly MelodiaPlaylist $playlist,
    ) {
        parent::__construct($fsm, $gameState);
    }

    public static function type(): string
    {
        return 'melodia';
    }

    public function name(): string
    {
        return 'Jaka to melodia';
    }

    /**
     * Stany ze spec 14 plus dwa przejscia, ktorych tam nie ma, a bez ktorych
     * gra utyka przy stole:
     *
     * - cancel(armed -> idle): nikt nie zabuzzowal, host chce zmienic utwor.
     *   Bez tego pokoj zostaje uzbrojony na zawsze, bo arm prowadzi tylko z idle.
     * - rearm(judging -> armed): druzyna odpowiedziala zle, wiec ten sam utwor
     *   wraca do gry dla pozostalych. To najczestszy przebieg rundy w praktyce.
     */
    public function definition(): GameDefinition
    {
        return new GameDefinition(
            type: self::type(),
            initialState: 'idle',
            states: ['idle', 'armed', 'buzzed', 'judging'],
            transitions: [
                new Transition('arm', 'idle', 'armed'),
                new Transition('buzz', 'armed', 'buzzed'),
                new Transition('judge', 'buzzed', 'judging'),
                new Transition('rearm', 'judging', 'armed'),
                new Transition('finish', 'judging', 'idle'),
                new Transition('cancel', 'armed', 'idle'),
            ],
        );
    }

    public function handleAction(Room $room, string $action, array $payload, Actor $actor): array
    {
        return match ($action) {
            'pick_deck' => $this->pickDeck($room, $payload, $actor),
            'next_track' => $this->nextTrack($room, $actor),
            'arm' => $this->arm($room, $actor),
            'buzz' => $this->buzz($room, $payload, $actor),
            'judge' => $this->judge($room, $payload, $actor),
            'rearm' => $this->rearm($room, $actor),
            'finish' => $this->finish($room, $actor),
            'cancel' => $this->cancel($room, $actor),
            default => throw new IllegalActionException(sprintf('Melodia nie zna akcji "%s".', $action)),
        };
    }

    /**
     * Widok hosta niesie tytul i wykonawce biezacego utworu - host musi wiedziec,
     * czy odpowiedz jest dobra. Ten widok nie trafia na telefony (spec 7).
     */
    public function hostView(Room $room): array
    {
        $ctx = $this->ctx($room);
        $track = $this->currentTrack($room);

        return [
            'state' => $this->state($room),
            'round' => $ctx['round'] ?? 0,
            'buzz' => $ctx['buzz'] ?? null,
            'lastResult' => $ctx['lastResult'] ?? null,
            'deck' => $ctx['deck'] ?? null,
            'deckName' => $ctx['deckName'] ?? null,
            'decks' => $this->playlist->availableDecks(),
            'trackNumber' => $ctx['trackNumber'] ?? 0,
            'trackTotal' => $ctx['trackTotal'] ?? 0,
            'track' => null === $track ? null : [
                'id' => $track->getId(),
                'title' => $track->getTitle(),
                'artist' => $track->getArtist(),
                'startAt' => $track->getStartAt(),
            ],
        ];
    }

    public function playerView(Room $room, Team $team): array
    {
        $ctx = $this->ctx($room);
        $buzz = $ctx['buzz'] ?? null;
        $winnerId = \is_array($buzz) ? ($buzz['teamId'] ?? null) : null;

        return [
            'state' => $this->state($room),
            'round' => $ctx['round'] ?? 0,
            // Telefon musi wiedziec, czy to on ma glos - stad osobna flaga
            // zamiast surowego id, ktorego sterownik i tak by nie porownal.
            'hasFloor' => $winnerId === $team->id,
            'armed' => $this->buzzer->isArmed($room->code),
        ];
    }

    /**
     * Wybor talii. Zeruje liste zagranych utworow, bo nowa talia to nowa gra.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<Message>
     */
    private function pickDeck(Room $room, array $payload, Actor $actor): array
    {
        $actor->requireHost();

        $slug = $payload['deck'] ?? null;

        if (!\is_string($slug) || !$this->playlist->deckExists($slug)) {
            throw new IllegalActionException('Nie ma takiej talii.');
        }

        $this->patchCtx($room, [
            'deck' => $slug,
            'deckName' => $this->playlist->deckName($slug),
            'trackTotal' => $this->playlist->countTracks($slug),
            'trackNumber' => 0,
            '_trackId' => null,
            '_played' => [],
            'revealed' => null,
        ]);

        return [Message::broadcast(new Event('deck_picked', [
            'deckName' => $this->playlist->deckName($slug),
            'tracks' => $this->playlist->countTracks($slug),
        ]))];
    }

    /**
     * Losuje kolejny utwor. Losowanie jest po stronie serwera (spec 14 - ta sama
     * zasada co przy kole fortuny), wiec ekran hosta niczego nie wybiera sam.
     *
     * @return list<Message>
     */
    private function nextTrack(Room $room, Actor $actor): array
    {
        $actor->requireHost();

        $ctx = $this->ctx($room);
        $deck = $ctx['deck'] ?? null;

        if (!\is_string($deck)) {
            throw new IllegalActionException('Najpierw wybierz talie.');
        }

        $played = \is_array($ctx['_played'] ?? null) ? array_values(array_filter($ctx['_played'], \is_int(...))) : [];
        $track = $this->playlist->drawNext($deck, $played);

        if (null === $track) {
            throw new IllegalActionException('Talia sie skonczyla - wybierz inna albo zrestartuj gre.');
        }

        $played[] = $track->getId();

        $this->patchCtx($room, [
            '_trackId' => $track->getId(),
            '_played' => $played,
            'trackNumber' => \count($played),
            'revealed' => null,
            'lastResult' => null,
        ]);

        // Telefony dostaja wylacznie numer utworu - tytul zdradzilby zagadke.
        return [Message::broadcast(new Event('track_ready', [
            'number' => \count($played),
            'total' => $ctx['trackTotal'] ?? 0,
        ]))];
    }

    /**
     * Host puszcza utwor i odblokowuje buzzery.
     *
     * Kolejnosc jest istotna: najpierw przejscie FSM, potem uzbrojenie buzzera.
     * Odwrotnie buzzery zapalilyby sie nawet wtedy, gdy gra jest w zlym stanie.
     *
     * @return list<Message>
     */
    private function arm(Room $room, Actor $actor): array
    {
        $actor->requireHost();

        if (null === $this->currentTrack($room)) {
            throw new IllegalActionException('Najpierw wylosuj utwor.');
        }

        $this->transition($room, 'arm');

        $this->patchCtx($room, [
            'round' => $this->buzzer->arm($room->code),
            'buzz' => null,
            'lastResult' => null,
        ]);

        return [];
    }

    /**
     * Nacisniecie buzzera. O zwyciezcy rozstrzyga BuzzerArbiter, nie ta metoda.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<Message>
     */
    private function buzz(Room $room, array $payload, Actor $actor): array
    {
        $team = $actor->requireTeam();

        // Numer rundy przychodzi z telefonu (dostal go eventem 'armed') - to on
        // pozwala rozpoznac nacisniecie spoznione o cala runde. Gdy go brak,
        // bierzemy biezacy, bo lepiej przyjac buzz niz odrzucic gracza za to,
        // ze jego telefon zgubil jeden event.
        $round = \is_int($payload['round'] ?? null)
            ? $payload['round']
            : $this->buzzer->currentRound($room->code);

        $result = $this->buzzer->buzz($room->code, $round, $team);

        if (!$result->accepted) {
            // Przegrany dowiaduje sie osobno: broadcast 'buzz' nie leci przy
            // odmowie, a telefon musi zdjac swoja lokalna blokade i pokazac,
            // kto byl szybszy.
            return [Message::toTeam($team->id, new Event('buzz_rejected', $result->toArray()))];
        }

        $this->transition($room, 'buzz');
        $this->patchCtx($room, ['buzz' => [
            'teamId' => $team->id,
            'teamName' => $team->name,
            'color' => $team->color,
            'round' => $round,
        ]]);

        return [];
    }

    /**
     * Host ocenia odpowiedz. Punkty przyznaje Scoreboard, wiec tablo
     * aktualizuje sie u wszystkich samo.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<Message>
     */
    private function judge(Room $room, array $payload, Actor $actor): array
    {
        $actor->requireHost();

        $buzz = $this->ctx($room)['buzz'] ?? null;

        if (!\is_array($buzz) || !\is_string($buzz['teamId'] ?? null)) {
            throw new IllegalActionException('Nie ma czyjej odpowiedzi oceniac.');
        }

        $correct = true === ($payload['correct'] ?? null);
        $points = \is_int($payload['points'] ?? null) ? $payload['points'] : 1;

        $this->transition($room, 'judge');
        $this->patchCtx($room, ['lastResult' => $correct ? 'correct' : 'wrong']);

        if ($correct) {
            $this->scoreboard->add($room->code, $buzz['teamId'], $points);
        }

        return [Message::broadcast(new Event('judged', [
            'teamId' => $buzz['teamId'],
            'teamName' => $buzz['teamName'] ?? null,
            'correct' => $correct,
        ]))];
    }

    /**
     * Zla odpowiedz - ten sam utwor wraca do gry dla pozostalych.
     *
     * Runda dostaje nowy numer, wiec druzyna, ktora wlasnie sie pomylila,
     * moze sprobowac ponownie. Wykluczanie jej nie jest tu zaimplementowane
     * celowo: przy stole rozstrzyga to host, a nie regulamin.
     *
     * @return list<Message>
     */
    private function rearm(Room $room, Actor $actor): array
    {
        $actor->requireHost();
        $this->transition($room, 'rearm');

        $this->patchCtx($room, [
            'round' => $this->buzzer->arm($room->code),
            'buzz' => null,
        ]);

        return [];
    }

    /** @return list<Message> */
    private function finish(Room $room, Actor $actor): array
    {
        $actor->requireHost();
        $this->transition($room, 'finish');
        $this->buzzer->disarm($room->code);
        $this->patchCtx($room, ['buzz' => null]);

        return $this->reveal($room);
    }

    /**
     * Odslania tytul po zamknieciu rundy.
     *
     * Dopiero tutaj, nigdy wczesniej: przy zlej odpowiedzi utwor wraca do gry
     * dla pozostalych (rearm), wiec pokazanie tytulu przy ocenianiu psuloby
     * zagadke wszystkim naraz.
     *
     * @return list<Message>
     */
    private function reveal(Room $room): array
    {
        $track = $this->currentTrack($room);

        if (null === $track) {
            return [];
        }

        $revealed = ['title' => $track->getTitle(), 'artist' => $track->getArtist()];
        $this->patchCtx($room, ['revealed' => $revealed]);

        return [Message::broadcast(new Event('track_revealed', $revealed))];
    }

    private function currentTrack(Room $room): ?\App\Entity\Track
    {
        $id = $this->ctx($room)['_trackId'] ?? null;

        return \is_int($id) ? $this->playlist->find($id) : null;
    }

    /**
     * Nikt nie zgadl - host gasi buzzery i zmienia utwor.
     *
     * @return list<Message>
     */
    private function cancel(Room $room, Actor $actor): array
    {
        $actor->requireHost();
        $this->transition($room, 'cancel');
        $this->buzzer->disarm($room->code);
        $this->patchCtx($room, ['buzz' => null, 'lastResult' => null]);

        // Nikt nie zgadl, wiec ciekawosc gosci tez trzeba zaspokoic.
        return $this->reveal($room);
    }
}
