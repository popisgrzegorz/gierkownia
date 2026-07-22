<?php

declare(strict_types=1);

namespace App\Core\Game;

use App\Core\Realtime\Message;
use App\Core\Room\Room;
use App\Core\Room\Team;

/**
 * Kontrakt modulu gry (spec 7).
 *
 * Rdzen nie wie nic o konkretnej grze: laduje modul po gameType i deleguje.
 * Dodanie kolejnej gry to nowa klasa implementujaca ten interfejs plus widoki
 * i talia tresci - **zero zmian w rdzeniu**.
 *
 * Moduly sa bezstanowe. Cala rozgrywka zyje w Redisie (GameState), bo backend
 * pod PHP-FPM obsluguje kolejne zadania w roznych procesach.
 */
interface GameInterface
{
    /** Identyfikator modulu, np. "melodia". Musi byc unikalny. */
    public static function type(): string;

    /** Nazwa do pokazania na ekranie wyboru gry. */
    public function name(): string;

    /** Stany, przejscia i stan poczatkowy tej gry. */
    public function definition(): GameDefinition;

    /**
     * Obsluguje akcje gracza lub hosta i zwraca wiadomosci do rozeslania.
     *
     * Modul sam pilnuje, czy akcja jest dozwolona dla tego aktora i w tym
     * stanie - do odrzucenia sluzy IllegalActionException. Publikacja nalezy
     * do rdzenia, wiec metoda niczego nie wysyla, tylko opisuje co ma polecieć.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<Message>
     */
    public function handleAction(Room $room, string $action, array $payload, Actor $actor): array;

    /**
     * Dane do renderu ekranu hosta (TV). Moga zawierac informacje tajne dla
     * graczy - ten widok nigdy nie trafia do telefonow.
     *
     * @return array<string, mixed>
     */
    public function hostView(Room $room): array;

    /**
     * Dane do renderu sterownika jednej druzyny.
     *
     * @return array<string, mixed>
     */
    public function playerView(Room $room, Team $team): array;
}
