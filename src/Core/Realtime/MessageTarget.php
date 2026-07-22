<?php

declare(strict_types=1);

namespace App\Core\Realtime;

enum MessageTarget
{
    /** Caly pokoj - tablo, fazy gry, wynik buzza. */
    case Room;

    /** Wylacznie ekran hosta - np. podglad rozwiazania. */
    case Host;

    /** Wylacznie jedna druzyna - np. slowo dla aktora. */
    case Team;
}
