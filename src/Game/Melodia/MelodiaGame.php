<?php

declare(strict_types=1);

namespace App\Game\Melodia;

use App\Core\Buzzer\BuzzerArbiter;
use App\Core\Buzzer\BuzzResult;
use App\Content\TrackDeck;
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
        private readonly TrackDeck $playlist,
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
            'pick_category' => $this->pickCategory($room, $payload, $actor),
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
            // Druzyny, ktore juz odpowiedzialy zle na TEN utwor - host ma
            // widziec, kto jeszcze moze probowac (patrz wrongTeams()).
            'wrongTeams' => $this->wrongTeams($room),
            // Tytul odsloniety juz calemu pokojowi. Ekran hosta pokazuje go
            // wylacznie na tej podstawie: 'track' nizej niesie tytul zawsze
            // (host go potrzebuje do oceny), ale telewizor oglada caly pokoj.
            'revealed' => $ctx['revealed'] ?? null,
            // Zaznaczone kategorie - po nich ekran hosta poznaje, czy jest już
            // co losować. Lista, bo wybór bywa mieszany.
            'picked' => $this->picked($room),
            'categoryName' => $ctx['categoryName'] ?? null,
            'categories' => $this->playlist->availableCategories(),
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
            // Ta druzyna juz probowala na TYM utworze i odpowiedziala zle -
            // telefon ma to pokazac zamiast aktywnego przycisku (patrz wrongTeams()).
            'excluded' => \in_array($team->id, $this->wrongTeams($room), true),
        ];
    }

    /**
     * Wybor kategorii. Zeruje liste zagranych utworow - nowy wybor to nowa gra.
     *
     * Kategorii moze byc kilka: host miesza "Hity" z "Dance", zeby wieczor nie
     * brzmial jednostajnie. Nagrania losuja sie wtedy ze wspolnej puli.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<Message>
     */
    private function pickCategory(Room $room, array $payload, Actor $actor): array
    {
        $actor->requireHost();

        $slugs = $this->playlist->known($this->slugs($payload));

        if ([] === $slugs) {
            throw new IllegalActionException('Nie ma takiej kategorii.');
        }

        $this->patchCtx($room, [
            'categories' => $slugs,
            'categoryName' => $this->playlist->name($slugs),
            'trackTotal' => $this->playlist->countTracks($slugs),
            'trackNumber' => 0,
            '_trackId' => null,
            '_played' => [],
            'revealed' => null,
        ]);

        return [Message::broadcast(new Event('category_picked', [
            'categoryName' => $this->playlist->name($slugs),
            'tracks' => $this->playlist->countTracks($slugs),
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

    /** @return list<string> */
    private function picked(Room $room): array
    {
        $slugs = $this->ctx($room)['categories'] ?? null;

        return \is_array($slugs) ? array_values(array_filter($slugs, '\is_string')) : [];
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
        $picked = $this->picked($room);

        if ([] === $picked) {
            throw new IllegalActionException('Najpierw wybierz kategorie.');
        }

        $played = \is_array($ctx['_played'] ?? null) ? array_values(array_filter($ctx['_played'], \is_int(...))) : [];
        $track = $this->playlist->drawNext($picked, $played);

        if (null === $track) {
            throw new IllegalActionException('Kategoria sie skonczyla - wybierz inna albo zrestartuj gre.');
        }

        $played[] = $track->getId();

        $this->patchCtx($room, [
            '_trackId' => $track->getId(),
            '_played' => $played,
            'trackNumber' => \count($played),
            'revealed' => null,
            'lastResult' => null,
            // Nowy utwor - stare wykluczenia nie maja juz znaczenia (patrz
            // wrongTeams() i judge()).
            'wrongTeams' => [],
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

        $round = $this->buzzer->arm($room->code);
        $this->patchCtx($room, [
            'round' => $round,
            'buzz' => null,
            'lastResult' => null,
        ]);

        // Zwykle pusta lista przy PIERWSZYM uzbrojeniu nowego utworu - ale
        // host moze tez wrocic do 'idle' i wcisnac "Graj" na tym samym
        // utworze bez losowania nowego (nextTrack() go nie wymaga), wiec
        // wykluczenia z poprzedniej rundy tego utworu maja wciaz obowiazywac.
        return $this->excludedMessages($room, $round);
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

        // Ta druzyna juz probowala na tym utworze i odpowiedziala zle -
        // sprawdzamy PRZED arbitrem, zeby nie zuzywac rundy na proba, ktora
        // i tak nie ma prawa wygrac (patrz judge() i wrongTeams()).
        if (\in_array($team->id, $this->wrongTeams($room), true)) {
            return [Message::toTeam($team->id, new Event('buzz_rejected', BuzzResult::excluded($round)->toArray()))];
        }

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

        if ($correct) {
            $this->scoreboard->add($room->code, $buzz['teamId'], $points);
            $this->patchCtx($room, ['lastResult' => 'correct']);
        } else {
            // Zla odpowiedz wyklucza TE druzyne z ponownej proby na TYM SAMYM
            // utworze - decyzja uzytkownika: kto sie pomyli, czeka na kolejna
            // piosenke zamiast probowac w kolko, az sie uda. Lista czysci sie
            // dopiero przy nastepnym wylosowanym utworze (patrz nextTrack()).
            $wrongTeams = $this->wrongTeams($room);
            $wrongTeams[] = $buzz['teamId'];
            $this->patchCtx($room, [
                'lastResult' => 'wrong',
                'wrongTeams' => array_values(array_unique($wrongTeams)),
            ]);
        }

        $messages = [Message::broadcast(new Event('judged', [
            'teamId' => $buzz['teamId'],
            'teamName' => $buzz['teamName'] ?? null,
            'correct' => $correct,
        ]))];

        // Trafiona odpowiedz konczy zagadke, wiec tytul moze wyjsc na ekran
        // od razu - i tak wszyscy go wlasnie uslyszeli. Przy zlej odpowiedzi
        // nie odslaniamy niczego: utwor wraca dla pozostalych druzyn (rearm).
        return $correct ? [...$messages, ...$this->reveal($room)] : $messages;
    }

    /**
     * Zla odpowiedz - ten sam utwor wraca do gry dla POZOSTALYCH druzyn.
     *
     * Runda dostaje nowy numer, ale druzyna, ktora wlasnie sie pomylila, jest
     * na liscie wykluczonych (dopisanej w judge()) i nie moze juz probowac na
     * tym samym utworze - decyzja uzytkownika, zeby jedna druzyna nie mogla
     * "przepytywac sie" w kolko az zgadnie. Serwer pilnuje tego sam
     * (buzz() odrzuca wykluczonych), wiec ten telefon dostaje od razu
     * powiadomienie zamiast czekac, az wcisnie przycisk i dopiero wtedy
     * usłyszy odmowe.
     *
     * @return list<Message>
     */
    private function rearm(Room $room, Actor $actor): array
    {
        $actor->requireHost();
        $this->transition($room, 'rearm');

        $round = $this->buzzer->arm($room->code);
        $this->patchCtx($room, [
            'round' => $round,
            'buzz' => null,
        ]);

        return $this->excludedMessages($room, $round);
    }

    /** @return list<string> */
    private function wrongTeams(Room $room): array
    {
        $teams = $this->ctx($room)['wrongTeams'] ?? null;

        return \is_array($teams) ? array_values(array_filter($teams, '\is_string')) : [];
    }

    /**
     * Powiadamia kazda wykluczona druzyne od razu po uzbrojeniu, zamiast
     * czekac, az sama sprobuje zabuzzowac i dopiero wtedy dostanie odmowe -
     * inaczej przycisk na jej telefonie mylnie wygladalby na aktywny.
     *
     * @return list<Message>
     */
    private function excludedMessages(Room $room, int $round): array
    {
        return array_map(
            static fn (string $teamId): Message => Message::toTeam(
                $teamId,
                new Event('buzz_rejected', BuzzResult::excluded($round)->toArray()),
            ),
            $this->wrongTeams($room),
        );
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
