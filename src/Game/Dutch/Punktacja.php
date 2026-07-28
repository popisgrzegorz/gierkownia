<?php

declare(strict_types=1);

namespace App\Game\Dutch;

/**
 * Liczenie rundy Dutcha: najnizsza suma kart wygrywa.
 *
 * Zasady pakietu startowego (za artykulem 8gates): kazda karta warta tyle, ile
 * mowi `Karty::value` (as 1, figury 10), a wygrywa najnizsza suma. Wolanie
 * "Dutch" ma sens tylko z najnizszym wynikiem - bledny call kosztuje ustalona
 * kare doliczana do sumy wolajacego. Kare i to, czy call byl trafny, liczymy tu,
 * a nie w module: to jedyne miejsce, w ktorym da sie skrzywdzic konkretna
 * druzyne, a przy stole nikt nie bedzie przegladal Redisa, zeby udowodnic swoje.
 *
 * Trafny call = wolajacy ma **samodzielnie** najnizsza sume. Remis na dole
 * traktujemy jako nietrafiony: "mialem najnizszy" nie znaczy "jeden z kilku
 * najnizszych". Dzieki temu ryzykowny call przy wyrownanym stole cos kosztuje.
 */
final class Punktacja
{
    /**
     * @param array<string, list<int>> $hands teamId => cztery karty (id 0-51)
     *
     * @return array{
     *     raw: array<string, int>,
     *     sums: array<string, int>,
     *     winners: list<string>,
     *     calledBy: ?string,
     *     callCorrect: ?bool,
     *     penalty: int
     * }
     */
    public static function score(array $hands, ?string $calledBy, int $penalty): array
    {
        $raw = [];

        foreach ($hands as $teamId => $cards) {
            // Puste pola (dorzucone karty, wartość -1) nie liczą się do sumy.
            $sum = 0;

            foreach ($cards as $card) {
                if ($card >= 0) {
                    $sum += Karty::value($card);
                }
            }

            $raw[$teamId] = $sum;
        }

        $callCorrect = null;
        $applied = 0;
        $sums = $raw;

        if (null !== $calledBy && \array_key_exists($calledBy, $raw)) {
            $callCorrect = self::isSoleLowest($calledBy, $raw);

            if (!$callCorrect) {
                // Blad kosztuje: kara doklada sie do sumy, wiec wolajacy zwykle
                // spada z podium, na ktore chcial wejsc.
                $sums[$calledBy] += $penalty;
                $applied = $penalty;
            }
        }

        return [
            'raw' => $raw,
            'sums' => $sums,
            'winners' => self::lowest($sums),
            'calledBy' => $calledBy,
            'callCorrect' => $callCorrect,
            'penalty' => $applied,
        ];
    }

    /**
     * Czy dana druzyna ma samodzielnie najnizszy wynik surowy.
     *
     * @param array<string, int> $raw
     */
    private static function isSoleLowest(string $teamId, array $raw): bool
    {
        $mine = $raw[$teamId];

        foreach ($raw as $other => $sum) {
            if ($other !== $teamId && $sum <= $mine) {
                return false;
            }
        }

        return true;
    }

    /**
     * Druzyny z najnizsza suma - moze byc kilka, gdy remisuja.
     *
     * @param array<string, int> $sums
     *
     * @return list<string>
     */
    private static function lowest(array $sums): array
    {
        if ([] === $sums) {
            return [];
        }

        $min = min($sums);

        return array_keys(array_filter($sums, static fn (int $sum): bool => $sum === $min));
    }
}
