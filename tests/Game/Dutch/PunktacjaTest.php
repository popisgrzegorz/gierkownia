<?php

declare(strict_types=1);

namespace App\Tests\Game\Dutch;

use App\Game\Dutch\Punktacja;
use PHPUnit\Framework\TestCase;

/**
 * Liczenie rundy Dutcha. To jedyne miejsce, w ktorym da sie skrzywdzic
 * konkretna druzyne - a przy stole nikt nie przeglada Redisa, zeby udowodnic
 * swoje.
 */
final class PunktacjaTest extends TestCase
{
    private const PENALTY = 5;

    /** As+2+3+4 = 10, cztery figury = 40. Najnizsza suma wygrywa. */
    public function testLowestSumWins(): void
    {
        $result = Punktacja::score([
            'a' => [0, 1, 2, 3],      // A 2 3 4 = 10
            'b' => [10, 11, 12, 9],   // J Q K 10 = 40
        ], null, self::PENALTY);

        self::assertSame(10, $result['raw']['a']);
        self::assertSame(40, $result['raw']['b']);
        self::assertSame(['a'], $result['winners']);
        self::assertNull($result['callCorrect']);
    }

    /** Remis na dole daje kilku zwyciezcow. */
    public function testTieForLowestGivesSeveralWinners(): void
    {
        $result = Punktacja::score([
            'a' => [0, 1, 2, 3],  // 10
            'b' => [3, 2, 1, 0],  // 10
            'c' => [12, 12, 12, 12], // 40 (te same figury tylko do sumy)
        ], null, self::PENALTY);

        self::assertSame(['a', 'b'], $result['winners']);
    }

    /** Trafny call: wolajacy ma samodzielnie najnizszy wynik - bez kary. */
    public function testCorrectCallScoresWithoutPenalty(): void
    {
        $result = Punktacja::score([
            'a' => [0, 1, 2, 3],      // 10 - najnizej
            'b' => [10, 11, 12, 9],   // 40
        ], 'a', self::PENALTY);

        self::assertTrue($result['callCorrect']);
        self::assertSame(0, $result['penalty']);
        self::assertSame(10, $result['sums']['a']);
        self::assertSame(['a'], $result['winners']);
    }

    /** Bledny call: wolajacy nie mial najnizej - kara doklada sie do sumy. */
    public function testWrongCallAddsPenalty(): void
    {
        $result = Punktacja::score([
            'a' => [0, 1, 2, 3],      // 10
            'b' => [4, 5, 0, 1],      // 5+6+1+2 = 14 - wola, choc nie ma najnizej
        ], 'b', self::PENALTY);

        self::assertFalse($result['callCorrect']);
        self::assertSame(self::PENALTY, $result['penalty']);
        self::assertSame(14 + self::PENALTY, $result['sums']['b']);
        self::assertSame(['a'], $result['winners']);
    }

    /**
     * Remis na dole liczy sie przeciw wolajacemu: "mialem najnizszy" to nie
     * "jeden z kilku najnizszych". Kara spada, a wygrywa ten drugi.
     */
    public function testCallOnaTieCountsAsWrong(): void
    {
        $result = Punktacja::score([
            'a' => [0, 1, 2, 3],  // 10 - wola
            'b' => [3, 2, 1, 0],  // 10 - tyle samo
        ], 'a', self::PENALTY);

        self::assertFalse($result['callCorrect']);
        self::assertSame(10 + self::PENALTY, $result['sums']['a']);
        self::assertSame(['b'], $result['winners']);
    }

    /** Bez wolania kara nie wchodzi, choćby wynik był wysoki. */
    public function testNoCallNoPenalty(): void
    {
        $result = Punktacja::score([
            'a' => [12, 12, 12, 12], // 40
        ], null, self::PENALTY);

        self::assertSame(40, $result['sums']['a']);
        self::assertNull($result['callCorrect']);
        self::assertSame(0, $result['penalty']);
    }
}
