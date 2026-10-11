<?php

declare(strict_types=1);

namespace App\Game\OstatniaLitera;

/**
 * Czy slowo istnieje w jezyku polskim.
 *
 * Osobny, maly interfejs zamiast wstrzykiwania konkretnej implementacji -
 * zeby test mogl podstawic atrape (stala lista slow) bez zaleznosci od
 * zainstalowanego hunspella na maszynie, ktora akurat uruchamia testy.
 */
interface WordJudge
{
    public function isKnown(string $word): bool;
}
