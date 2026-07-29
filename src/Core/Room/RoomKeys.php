<?php

declare(strict_types=1);

namespace App\Core\Room;

/**
 * Jedyne miejsce, ktore wie jak nazywaja sie klucze pokoju w Redisie (spec 5.1).
 *
 * Kazdy klucz musi dac sie odtworzyc z samego kodu pokoju, zeby reset i wygasanie
 * TTL mogly zdjac caly stan bez sledzenia co gra zdazyla zapisac.
 */
final class RoomKeys
{
    public static function meta(string $code): string
    {
        return sprintf('room:%s:meta', $code);
    }

    public static function teams(string $code): string
    {
        return sprintf('room:%s:teams', $code);
    }

    /**
     * Licznik kolejnosci dolaczania. Osobny klucz, bo czas z dokladnoscia do
     * sekundy nie porzadkuje druzyn, ktore skanuja QR jednoczesnie - a tak jest
     * zawsze, gdy host pokaze kod na telewizorze.
     */
    public static function teamSeq(string $code): string
    {
        return sprintf('room:%s:teams:seq', $code);
    }

    public static function score(string $code, string $teamId): string
    {
        return sprintf('room:%s:score:%s', $code, $teamId);
    }

    /**
     * Druzyny odtworzone z zapisu, po ktore jeszcze nikt zywy sie nie zglosil.
     * Pierwsze zadanie z pasujaca nazwa moze je przejac bez dowodu wlasnosci -
     * fizycznie nikt inny nie zdazyl tego zrobic w nowym pokoju - a kazde kolejne
     * juz musi udowodnic, ze to to samo urzadzenie (RoomManager::join).
     */
    public static function unclaimedTeams(string $code): string
    {
        return sprintf('room:%s:teams:unclaimed', $code);
    }

    /**
     * Tabela turnieju: punkty za miejsca zebrane przez wszystkie rozegrane gry.
     * Poza prefiksem game:, wiec reset rozgrywki jej nie zdejmuje (turniej trwa
     * ponad pojedyncza gra), ale wygasa razem z pokojem po TTL.
     */
    public static function tournament(string $code): string
    {
        return sprintf('room:%s:tournament', $code);
    }

    public static function scorePattern(string $code): string
    {
        return sprintf('room:%s:score:*', $code);
    }

    public static function gameRound(string $code): string
    {
        return sprintf('room:%s:game:round', $code);
    }

    public static function gameArmed(string $code): string
    {
        return sprintf('room:%s:game:armed', $code);
    }

    public static function gameWinner(string $code, int $round): string
    {
        return sprintf('room:%s:game:winner:%d', $code, $round);
    }

    /**
     * Kolejnosc druzyn w grach turowych. Pod prefiksem game:, wiec reset
     * rozgrywki zdejmuje ja razem z reszta stanu gry.
     */
    public static function turnOrder(string $code): string
    {
        return sprintf('room:%s:game:turn:order', $code);
    }

    public static function turnIndex(string $code): string
    {
        return sprintf('room:%s:game:turn:index', $code);
    }

    public static function gameState(string $code): string
    {
        return sprintf('room:%s:game:state', $code);
    }

    public static function gameCtx(string $code): string
    {
        return sprintf('room:%s:game:ctx', $code);
    }

    public static function gameDeadline(string $code): string
    {
        return sprintf('room:%s:game:deadline', $code);
    }

    /** Wszystko co nalezy do pokoju - do resetu i sprzatania. */
    public static function allPattern(string $code): string
    {
        return sprintf('room:%s:*', $code);
    }

    /** Klucze gry (bez meta i druzyn) - reset rozgrywki bez rozwalania pokoju. */
    public static function gamePattern(string $code): string
    {
        return sprintf('room:%s:game:*', $code);
    }
}
