<?php

declare(strict_types=1);

namespace App\Tests\Game\Kolo;

use App\Game\Kolo\PuzzleBoard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Plansza hasla. Wszystkie pulapki kola siedza tutaj: polskie znaki, spacje,
 * myslniki i to, ze "Ł" nie jest "L".
 */
final class PuzzleBoardTest extends TestCase
{
    public function testHiddenPhraseShowsOnlySpacesAndPunctuation(): void
    {
        // Spacje zostaja odkryte celowo - to one zdradzaja budowe hasla
        // i bez nich plansza bylaby jednym dlugim ciagiem podkreslen.
        self::assertSame('_____ __ ______', PuzzleBoard::mask('Wlazl na plotek', []));
        self::assertSame('____-____', PuzzleBoard::mask('Full-Stop', []));
    }

    public function testRevealedLettersAppearEverywhereTheyOccur(): void
    {
        self::assertSame('_A_A _ _A_A', PuzzleBoard::mask('Kasa i kama', ['A']));
    }

    /** Polskie znaki to osobne litery - tak jest w teleturnieju i tak jest trudniej. */
    public function testDiacriticsAreSeparateLetters(): void
    {
        self::assertSame('Ł_D__', PuzzleBoard::mask('Łódka', ['Ł', 'D']));
        self::assertSame(0, PuzzleBoard::occurrences('Łódka', 'L'));
        self::assertSame(1, PuzzleBoard::occurrences('Łódka', 'Ł'));
        self::assertSame(0, PuzzleBoard::occurrences('Łódka', 'O'));
        self::assertSame(1, PuzzleBoard::occurrences('Łódka', 'Ó'));
    }

    public function testLetterCaseDoesNotMatterWhenGuessing(): void
    {
        self::assertSame(2, PuzzleBoard::occurrences('Kasa Kwiatowa', 'k'));
        self::assertSame(2, PuzzleBoard::occurrences('Kasa Kwiatowa', ' K '));
    }

    public function testMissedLetterCountsZero(): void
    {
        self::assertSame(0, PuzzleBoard::occurrences('Kasa', 'Z'));
    }

    public function testPuzzleIsSolvedOnlyWhenEveryLetterIsOut(): void
    {
        self::assertFalse(PuzzleBoard::isSolved('Ala ma kota', ['A']));
        self::assertTrue(PuzzleBoard::isSolved('Ala ma kota', ['A', 'L', 'M', 'K', 'O', 'T']));
    }

    public function testPunctuationDoesNotBlockSolving(): void
    {
        self::assertTrue(PuzzleBoard::isSolved('A-B!', ['A', 'B']));
    }

    #[DataProvider('answers')]
    public function testAnswerIsComparedLeniently(string $answer, bool $expected): void
    {
        self::assertSame($expected, PuzzleBoard::matches('Kasa Kwiatowa', $answer));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function answers(): iterable
    {
        yield 'dokladnie' => ['Kasa Kwiatowa', true];
        // Gracz wpisuje kciukiem na telefonie, a nie przepisuje co do znaku.
        yield 'inna wielkosc liter' => ['kasa kwiatowa', true];
        yield 'podwojna spacja' => ['Kasa  Kwiatowa', true];
        yield 'spacje na koncach' => ['  Kasa Kwiatowa  ', true];
        yield 'literowka' => ['Kasa Kwiatowe', false];
        yield 'brak slowa' => ['Kasa', false];
        yield 'bez polskich znakow' => ['Kasa Kwiatowa!', false];
    }

    #[DataProvider('letters')]
    public function testOnlySingleLettersAreAccepted(string $input, bool $expected): void
    {
        self::assertSame($expected, PuzzleBoard::isValidLetter($input));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function letters(): iterable
    {
        yield 'litera' => ['A', true];
        yield 'mala litera' => ['z', true];
        yield 'polska litera' => ['Ż', true];
        yield 'ze spacjami' => [' A ', true];
        yield 'dwie litery' => ['AB', false];
        yield 'cyfra' => ['7', false];
        yield 'spacja' => [' ', false];
        yield 'pusty' => ['', false];
        yield 'znak' => ['-', false];
    }
}
