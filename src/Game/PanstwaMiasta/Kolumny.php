<?php

declare(strict_types=1);

namespace App\Game\PanstwaMiasta;

/**
 * Zestawy kolumn do wypelnienia i litery do losowania.
 *
 * Kolumny siedza w kodzie, a nie w slowniku hasel: to naglowki tabeli, a nie
 * tresc do zgadywania. Slownik odpowiada na pytanie "co odgadujemy", a tu
 * pytanie brzmi "jakie rubryki ma kartka" - i odpowiedz jest ta sama na kazdej
 * imprezie.
 */
final class Kolumny
{
    /** @var array<string, array{name: string, columns: list<string>}> */
    public const PRESETS = [
        'klasyczne' => [
            'name' => 'Klasyczne',
            'columns' => ['Państwo', 'Miasto', 'Roślina', 'Zwierzę', 'Imię', 'Rzecz'],
        ],
        'krotkie' => [
            'name' => 'Krótkie (szybka runda)',
            'columns' => ['Państwo', 'Miasto', 'Zwierzę', 'Rzecz'],
        ],
        'rozszerzone' => [
            'name' => 'Rozszerzone',
            'columns' => ['Państwo', 'Miasto', 'Roślina', 'Zwierzę', 'Imię', 'Rzecz', 'Zawód', 'Potrawa'],
        ],
    ];

    public const DEFAULT_PRESET = 'klasyczne';

    /**
     * Litery do losowania.
     *
     * Bez Q, V, X i Y - na te litery nie ma polskiego miasta ani rosliny,
     * wiec runda konczylaby sie szescioma pustymi rubrykami. Bez polskich
     * znakow diakrytycznych z tego samego powodu.
     */
    private const LETTERS = [
        'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K',
        'L', 'M', 'N', 'O', 'P', 'R', 'S', 'T', 'U', 'W', 'Z',
    ];

    public static function exists(string $preset): bool
    {
        return isset(self::PRESETS[$preset]);
    }

    /** @return list<string> */
    public static function columns(string $preset): array
    {
        return self::PRESETS[$preset]['columns'] ?? self::PRESETS[self::DEFAULT_PRESET]['columns'];
    }

    /** @return list<array{key: string, name: string, columns: int}> */
    public static function choices(): array
    {
        $out = [];

        foreach (self::PRESETS as $key => $preset) {
            $out[] = ['key' => $key, 'name' => $preset['name'], 'columns' => \count($preset['columns'])];
        }

        return $out;
    }

    /**
     * Losuje litere, pomijajac te juz zagrane.
     *
     * Gdy wyczerpia sie wszystkie, zaczynamy od nowa - lepiej powtorzyc litere
     * niz zakonczyc zabawe komunikatem o braku liter.
     *
     * @param list<string> $used
     */
    public static function drawLetter(array $used): string
    {
        $left = array_values(array_diff(self::LETTERS, $used));

        if ([] === $left) {
            $left = self::LETTERS;
        }

        return $left[random_int(0, \count($left) - 1)];
    }
}
