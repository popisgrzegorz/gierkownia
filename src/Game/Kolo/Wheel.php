<?php

declare(strict_types=1);

namespace App\Game\Kolo;

/**
 * Koło. Losowanie jest autorytatywne po stronie serwera (spec 14) - animacja
 * na ekranie hosta tylko pokazuje wynik, ktory juz zapadl.
 *
 * Gdyby losowala przegladarka, wynik zalezalby od tego, co siedzi w karcie
 * telewizora - a to jedyne miejsce w grze, gdzie stawka sa punkty za nic.
 */
final class Wheel
{
    public const BANKRUT = 'bankrut';
    public const STOP = 'stop';

    /**
     * Sektory w kolejnosci zgodnej z ruchem wskazowek zegara.
     *
     * Dwa pola "bankrut" po przeciwnych stronach, a nie obok siebie: przy
     * dwunastu polach sasiadujace pulapki robia z jednej cwiartki martwa strefe.
     *
     * @var list<int|string>
     */
    private const SECTORS = [
        200, 400, self::BANKRUT, 600, 300, self::STOP,
        500, 800, 200, 1000, 400, self::BANKRUT,
    ];

    /** @return list<int|string> */
    public static function sectors(): array
    {
        return self::SECTORS;
    }

    public static function count(): int
    {
        return \count(self::SECTORS);
    }

    /**
     * Kreci kolem. Zwraca indeks sektora - ekran hosta zatrzyma na nim animacje.
     *
     * @return array{index: int, value: int|string}
     */
    public static function spin(): array
    {
        $index = random_int(0, self::count() - 1);

        return ['index' => $index, 'value' => self::SECTORS[$index]];
    }

    public static function isPenalty(int|string $value): bool
    {
        return self::BANKRUT === $value || self::STOP === $value;
    }

    /** @return array{index: int, value: int|string}|null */
    public static function at(int $index): ?array
    {
        return isset(self::SECTORS[$index]) ? ['index' => $index, 'value' => self::SECTORS[$index]] : null;
    }
}
