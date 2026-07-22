<?php

declare(strict_types=1);

namespace App\Core\Realtime;

/**
 * Topici Mercure per pokoj (spec 6).
 *
 * Podzial nie jest kosmetyczny: w kalamburach haslo idzie tylko do aktora,
 * a w kole tylko host widzi rozwiazanie. Gdyby wszystko szlo jednym topikiem,
 * kazdy zgadujacy podejrzalby odpowiedz w narzedziach deweloperskich.
 */
final class Topics
{
    /** Broadcast: fazy gry, tablo, wynik buzza, timer. Jawne dla calego pokoju. */
    public static function room(string $code): string
    {
        return sprintf('room/%s', $code);
    }

    /** Tylko ekran hosta - np. podglad hasla. Publikowane jako private update. */
    public static function host(string $code): string
    {
        return sprintf('room/%s/host', $code);
    }

    /** Tylko jedna druzyna - np. slowo dla aktora. Publikowane jako private update. */
    public static function team(string $code, string $teamId): string
    {
        return sprintf('room/%s/team/%s', $code, $teamId);
    }
}
