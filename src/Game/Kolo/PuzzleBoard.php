<?php

declare(strict_types=1);

namespace App\Game\Kolo;

/**
 * Plansza hasla: maskowanie, zliczanie trafien, sprawdzanie rozwiazania.
 *
 * Czyste funkcje bez stanu i bez Redisa - to jedyna czesc kola, ktora latwo
 * pomylic (polskie znaki, spacje, myslniki), wiec siedzi osobno i ma wlasne testy.
 *
 * Polskie litery diakrytyczne sa osobnymi literami: "Ł" nie odslania "L".
 * Tak jest w teleturnieju i tak jest trudniej - o to chodzi.
 */
final class PuzzleBoard
{
    private const HIDDEN = '_';

    /**
     * Zamienia nieodgadniete litery na podkreslenia, zostawiajac spacje
     * i znaki interpunkcyjne - to one zdradzaja dlugosc slow i budowe hasla.
     *
     * @param list<string> $revealed
     */
    public static function mask(string $phrase, array $revealed): string
    {
        $out = '';

        foreach (self::chars($phrase) as $char) {
            if (!self::isLetter($char)) {
                $out .= $char;

                continue;
            }

            $out .= \in_array($char, $revealed, true) ? $char : self::HIDDEN;
        }

        return $out;
    }

    /** Ile razy litera wystepuje w hasle. Zero znaczy "pudlo". */
    public static function occurrences(string $phrase, string $letter): int
    {
        $letter = self::normalize($letter);
        $count = 0;

        foreach (self::chars($phrase) as $char) {
            if ($char === $letter) {
                ++$count;
            }
        }

        return $count;
    }

    /** @param list<string> $revealed */
    public static function isSolved(string $phrase, array $revealed): bool
    {
        foreach (self::chars($phrase) as $char) {
            if (self::isLetter($char) && !\in_array($char, $revealed, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Porownanie odpowiedzi z haslem.
     *
     * Roznice w wielkosci liter i w liczbie spacji odpuszczamy: gracz wpisuje
     * odpowiedz kciukiem na telefonie, a nie przepisuje ja co do znaku.
     */
    public static function matches(string $phrase, string $answer): bool
    {
        return self::compact($phrase) === self::compact($answer);
    }

    /** Sprawdza, czy to pojedyncza litera - telefon moze przyslac cokolwiek. */
    public static function isValidLetter(string $letter): bool
    {
        $letter = self::normalize($letter);

        return 1 === mb_strlen($letter) && self::isLetter($letter);
    }

    public static function normalize(string $letter): string
    {
        return mb_strtoupper(trim($letter));
    }

    /** @return list<string> */
    private static function chars(string $text): array
    {
        return mb_str_split(mb_strtoupper($text));
    }

    private static function isLetter(string $char): bool
    {
        return 1 === preg_match('/^\p{L}$/u', $char);
    }

    private static function compact(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtoupper($text)) ?? '');
    }
}
