<?php

declare(strict_types=1);

namespace App\Game\Dutch;

/**
 * Talia i karty do Dutcha.
 *
 * Karte trzymamy jako liczbe 0-51: figura to `id % 13` (0 = as, 12 = krol),
 * kolor to `id / 13`. Jeden int zamiast obiektu, bo cala talia siedzi
 * w kontekscie Redisa jako lista liczb - lekko i bez serializacji obiektow.
 *
 * W pakiecie startowym nie ma jokerow ani kart specjalnych: liczy sie sama
 * wartosc punktowa (do zsumowania) i etykieta (do pokazania odslonietej karty).
 * Dolozenie mocy damy/waleta to osobna decyzja - patrz DutchGame.
 */
final class Karty
{
    /** Figury w kolejnosci od asa; indeks to `id % 13`. */
    private const RANKS = ['A', '2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K'];

    /** Kolory; indeks to `id / 13`. Kier i karo sa czerwone. */
    private const SUITS = ['♠', '♥', '♦', '♣'];

    public const DECK_SIZE = 52;

    /**
     * Pelna talia jako lista id 0-51. Tasowanie nalezy do modulu - tu jest
     * tylko material, zeby ta sama lista dala sie odtworzyc w tescie.
     *
     * @return list<int>
     */
    public static function deck(): array
    {
        return range(0, self::DECK_SIZE - 1);
    }

    /**
     * Wartosc punktowa: as = 1, 2-10 wprost z figury, walet/dama/krol = 10.
     * W Dutchu liczy sie najnizsza suma, wiec figury sa najdrozsze.
     */
    public static function value(int $id): int
    {
        $rank = self::rank($id);

        // As (rank 0) to 1 punkt; figury (walet, dama, krol - rank 10-12) po 10;
        // reszta to sama liczba oczek (rank 1 = dwojka = 2 punkty).
        return match (true) {
            0 === $rank => 1,
            $rank >= 10 => 10,
            default => $rank + 1,
        };
    }

    /** Etykieta do pokazania odslonietej karty, np. "A♠", "10♦", "K♣". */
    public static function label(int $id): string
    {
        return self::RANKS[self::rank($id)].self::SUITS[self::suit($id)];
    }

    /** Czy karta jest czerwona - do pokolorowania jej na ekranie. */
    public static function isRed(int $id): bool
    {
        return \in_array(self::suit($id), [1, 2], true);
    }

    /** Figura karty: 0 = as, 1-9 = 2-10, 10-12 = walet/dama/krol. */
    public static function rank(int $id): int
    {
        return $id % 13;
    }

    /**
     * Moc karty (zasady domowe): dama pozwala podejrzeć WŁASNĄ kartę, walet -
     * zamienić jedną kartę między wybranymi graczami. Reszta jest bez mocy,
     * liczą się tylko punkty.
     */
    public static function power(int $id): ?string
    {
        return match (self::rank($id)) {
            10 => 'swap',      // walet - zamiana dwóch kart na stole
            11 => 'peek_own',  // dama - podgląd własnej karty
            default => null,
        };
    }

    private static function suit(int $id): int
    {
        return intdiv($id, 13);
    }
}
