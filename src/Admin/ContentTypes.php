<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\Category;

/**
 * Rodzaje tresci obslugiwane przez panel i gry, ktore z nich korzystaja.
 *
 * Lista jest tu, a nie w rejestrze gier, bo tresci zbiera sie **zanim** powstanie
 * modul gry - hasla do panstw-miast mozna wpisywac juz teraz, choc sama gra
 * dopiero czeka na swoja kolej. Rejestr gier zna wylacznie to, w co da sie zagrac.
 *
 * Dwie osie, bo to dwa rozne pytania (patrz Category):
 * rodzaj tresci mowi, co lezy w kategorii, a lista gier - kto moze po to siegnac.
 */
final class ContentTypes
{
    /** @var array<string, array{label: string, plural: string, singular: string}> */
    private const KINDS = [
        Category::KIND_MELODIA => ['label' => 'Nagrania', 'plural' => 'Nagrania', 'singular' => 'nagranie'],
        Category::KIND_HASLA => ['label' => 'Słownik haseł', 'plural' => 'Hasła', 'singular' => 'hasło'],
    ];

    /** @var array<string, string> typ gry => nazwa dla czlowieka */
    private const GAMES = [
        'melodia' => 'Jaka to melodia',
        'kolo' => 'Koło fortuny',
        'kalambury' => 'Kalambury',
    ];

    /**
     * Gry korzystajace ze slownika hasel. Dopisanie tu nowej gry na slowa
     * wystarczy, zeby dalo sie jej udostepnic istniejace kategorie.
     *
     * @var list<string>
     */
    private const PHRASE_GAMES = ['kolo', 'kalambury'];

    /** @return list<string> */
    public static function kinds(): array
    {
        return array_keys(self::KINDS);
    }

    public static function exists(string $kind): bool
    {
        return isset(self::KINDS[$kind]);
    }

    public static function label(string $kind): string
    {
        return self::KINDS[$kind]['label'] ?? $kind;
    }

    public static function plural(string $kind): string
    {
        return self::KINDS[$kind]['plural'] ?? 'Pozycje';
    }

    public static function singular(string $kind): string
    {
        return self::KINDS[$kind]['singular'] ?? 'pozycja';
    }

    public static function gameName(string $gameType): string
    {
        return self::GAMES[$gameType] ?? $gameType;
    }

    /** @return list<string> */
    public static function phraseGames(): array
    {
        return self::PHRASE_GAMES;
    }

    /** @return array<string, string> nazwa rodzaju => klucz, do listy wyboru */
    public static function kindChoices(): array
    {
        $out = [];

        foreach (self::KINDS as $kind => $meta) {
            $out[$meta['label']] = $kind;
        }

        return $out;
    }

    /** @return array<string, string> nazwa gry => typ, do pola wielokrotnego wyboru */
    public static function phraseGameChoices(): array
    {
        $out = [];

        foreach (self::PHRASE_GAMES as $game) {
            $out[self::gameName($game)] = $game;
        }

        return $out;
    }

    /**
     * Gry, ktore moga korzystac z kategorii danego rodzaju.
     *
     * @return list<string>
     */
    public static function gamesForKind(string $kind): array
    {
        return Category::KIND_MELODIA === $kind ? ['melodia'] : self::PHRASE_GAMES;
    }
}
