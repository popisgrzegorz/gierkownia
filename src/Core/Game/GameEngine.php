<?php

declare(strict_types=1);

namespace App\Core\Game;

use App\Core\Game\Fsm\GameFsm;
use App\Core\Realtime\Event;
use App\Core\Realtime\Message;
use App\Core\Realtime\Publisher;
use App\Core\Room\Room;
use App\Core\Room\RoomLock;
use App\Core\Room\RoomManager;
use App\Core\Room\Team;

/**
 * Wejscie rdzenia do rozgrywki: laduje modul po gameType i deleguje (spec 7).
 *
 * Kontrolery rozmawiaja wylacznie z tym serwisem - nie znaja ani rejestru,
 * ani FSM, ani modulow.
 */
final readonly class GameEngine
{
    public function __construct(
        private GameRegistry $registry,
        private GameState $state,
        private GameFsm $fsm,
        private RoomManager $rooms,
        private Publisher $publisher,
        private RoomLock $lock,
    ) {
    }

    /**
     * Wybiera gre dla pokoju i ustawia ja w stanie poczatkowym.
     *
     * Kontekst poprzedniej gry czyscimy zawsze - inaczej haslo z koła zostaloby
     * w Redisie i wyciekloby do modulu, ktory sie go nie spodziewa.
     *
     * Pod zamkiem pokoju: dwa rownolegle "wybierz gre" (np. host klika dwa razy)
     * inaczej moglyby przeplesc zapisy i zostawic niespojny stan miedzy
     * gameType, stanem FSM i faza.
     */
    public function start(Room $room, string $gameType): GameInterface
    {
        return $this->lock->synchronized($room->code, function () use ($room, $gameType): GameInterface {
            $game = $this->registry->get($gameType);

            // Pelne sprzatanie kluczy gry (round, armed, winner, turn:*, deadline),
            // nie tylko state+ctx - inaczej termin z poprzedniej gry (kazdy zegar
            // gry siedzi pod tym samym kluczem) wyciekal do nowej.
            $this->rooms->clearGameKeys($room->code);
            $this->rooms->setGameType($room->code, $gameType);

            $definition = $game->definition();
            $this->state->setState($room->code, $definition->initialState);
            $this->rooms->setPhase($room->code, $definition->initialState);

            $this->publisher->broadcast($room->code, new Event(Event::PHASE, [
                'gameType' => $gameType,
                'state' => $definition->initialState,
            ]));

            return $game;
        });
    }

    /**
     * Przekazuje akcje do modulu i rozsyla to, co modul zwrocil.
     *
     * Zmiane fazy ogłasza rdzen, a nie modul: porownujemy stan przed i po,
     * wiec zaden modul nie moze o tym zapomniec, a ekrany zawsze wiedza,
     * w ktorym miejscu gry sa.
     *
     * Cala metoda biegnie pod zamkiem pokoju: modul czyta i pisze kontekst
     * (GameState::mergeCtx) i marking FSM w kilku osobnych krokach, ktore
     * same w sobie nie sa atomowe. Bez zamka dwa rownolegle zadania dla tego
     * samego pokoju (dwa telefony piszace naraz w Panstwach-Miastach, dwa
     * kliknięcia "dobrze" w Melodii) czytaja ten sam stan sprzed zmiany, a
     * drugi zapis kasuje pierwszy - "Redis szereguje polecenia" nie broni
     * przed tym niczego, bo szereguje POJEDYNCZE polecenia, a nie ta cala
     * sekwencje odczyt-zmiana-zapis.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{state: string, ctx: array<string, mixed>}
     */
    public function handle(Room $room, string $action, array $payload, Actor $actor): array
    {
        return $this->lock->synchronized($room->code, function () use ($room, $action, $payload, $actor): array {
            $game = $this->gameOf($room);
            $definition = $game->definition();

            $before = $this->fsm->state($definition, $room->code);
            $messages = $game->handleAction($room, $action, $payload, $actor);
            $after = $this->fsm->state($definition, $room->code);

            $this->publisher->sendAll($room->code, $messages);

            if ($before !== $after) {
                $this->rooms->setPhase($room->code, $after);
                $this->publisher->broadcast($room->code, new Event(Event::PHASE, [
                    'state' => $after,
                    'ctx' => $this->publicCtx($room),
                ]));
            }

            // Publiczny kontekst takze w odpowiedzi HTTP: to samo zadanie wykonuje
            // telefon gracza, wiec pelne ctx oddaloby mu klucze prywatne modulu
            // (u melodii - id biezacego utworu i lista juz zagranych). Host bierze
            // szczegoly z osobnego, uwierzytelnionego widoku.
            return ['state' => $after, 'ctx' => $this->publicCtx($room)];
        });
    }

    /**
     * Modul wybrany dla pokoju.
     *
     * Typ gry czytamy z Redisa, a nie z przekazanego obiektu Room: to
     * niemutowalny snapshot, wiec ten sam egzemplarz wczytany przed start()
     * wciaz twierdzilby, ze gry nie ma. Jeden odczyt z LAN-owego Redisa jest
     * tanszy niz klasa bledow "dzialalo, dopoki nie odswiezylem strony".
     */
    public function gameOf(Room $room): GameInterface
    {
        $gameType = $this->rooms->getOrFail($room->code)->gameType;

        if (null === $gameType) {
            throw new IllegalActionException('W tym pokoju nie wybrano jeszcze gry.');
        }

        return $this->registry->get($gameType);
    }

    public function hasGame(Room $room): bool
    {
        $gameType = $this->rooms->get($room->code)?->gameType;

        return null !== $gameType && $this->registry->has($gameType);
    }

    public function currentState(Room $room): string
    {
        return $this->fsm->state($this->gameOf($room)->definition(), $room->code);
    }

    /** @return array<string, mixed> */
    public function hostView(Room $room): array
    {
        return $this->gameOf($room)->hostView($room);
    }

    /** @return array<string, mixed> */
    public function playerView(Room $room, Team $team): array
    {
        return $this->gameOf($room)->playerView($room, $team);
    }

    /** Konczy gre i wraca do lobby, zostawiajac druzyny i punkty. */
    public function stop(Room $room): void
    {
        // Pelne sprzatanie kluczy gry - patrz komentarz w start().
        $this->rooms->clearGameKeys($room->code);
        // Bez tego pokoj dalej twierdzi, ze gra trwa - ekran hosta nie
        // pokazalby wyboru gry, a telefony zostalyby w starym sterowniku.
        $this->rooms->clearGameType($room->code);
        $this->rooms->setPhase($room->code, 'lobby');

        $this->publisher->broadcast($room->code, new Event(Event::PHASE, ['state' => 'lobby']));
    }

    /**
     * Kontekst obciety do tego, co wolno zobaczyc calemu pokojowi.
     *
     * Klucze zaczynajace sie od "_" sa prywatne dla modulu (np. _phrase
     * w kole fortuny). Broadcast leci na wszystkie telefony, wiec domyslne
     * wyslanie calego ctx zdradziloby rozwiazanie.
     *
     * @return array<string, mixed>
     */
    private function publicCtx(Room $room): array
    {
        return array_filter(
            $this->state->ctx($room->code),
            static fn (string $key): bool => !str_starts_with($key, '_'),
            \ARRAY_FILTER_USE_KEY,
        );
    }
}
