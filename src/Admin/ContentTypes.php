<?php

declare(strict_types=1);

namespace App\Admin;

/**
 * Rodzaje tresci obslugiwane przez panel.
 *
 * Lista jest tu, a nie w rejestrze gier, bo tresci zbiera sie **zanim** powstanie
 * modul gry - karty kalambur mozna wpisywac juz teraz, choc sama gra dopiero
 * czeka na swoja kolej. Rejestr gier zna wylacznie to, w co da sie zagrac.
 */
final class ContentTypes
{
    /** @var array<string, array{game: string, plural: string, singular: string}> */
    private const TYPES = [
        'melodia' => ['game' => 'Jaka to melodia', 'plural' => 'Nagrania', 'singular' => 'nagranie'],
        'kolo' => ['game' => 'Koło fortuny', 'plural' => 'Hasła', 'singular' => 'hasło'],
        'kalambury' => ['game' => 'Kalambury', 'plural' => 'Karty', 'singular' => 'karta'],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::TYPES);
    }

    public static function exists(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    public static function gameName(string $type): string
    {
        return self::TYPES[$type]['game'] ?? $type;
    }

    public static function plural(string $type): string
    {
        return self::TYPES[$type]['plural'] ?? 'Pozycje';
    }

    public static function singular(string $type): string
    {
        return self::TYPES[$type]['singular'] ?? 'pozycja';
    }

    /** @return array<string, string> typ => nazwa gry, do listy wyboru */
    public static function choices(): array
    {
        return array_map(static fn (array $meta): string => $meta['game'], self::TYPES);
    }
}
