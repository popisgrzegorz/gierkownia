<?php

declare(strict_types=1);

namespace App\Tests\Game\Dutch;

use App\Game\Dutch\Karty;
use PHPUnit\Framework\TestCase;

/**
 * Wartosci i etykiety kart. W Dutchu liczy sie najnizsza suma, wiec pomylka
 * w wycenie figury zmienia wynik calej rundy.
 */
final class KartyTest extends TestCase
{
    public function testAceIsWorthOneAndFiguresTen(): void
    {
        self::assertSame(1, Karty::value(0));   // as pik
        self::assertSame(2, Karty::value(1));   // dwojka pik
        self::assertSame(10, Karty::value(9));  // dziesiatka pik
        self::assertSame(10, Karty::value(10)); // walet
        self::assertSame(10, Karty::value(11)); // dama
        self::assertSame(10, Karty::value(12)); // krol
        self::assertSame(1, Karty::value(13));  // as kier - kolor nie zmienia wartosci
    }

    public function testDeckHasFiftyTwoUniqueCards(): void
    {
        $deck = Karty::deck();

        self::assertCount(52, $deck);
        self::assertCount(52, array_unique($deck));
    }

    public function testLabelJoinsRankAndSuit(): void
    {
        self::assertSame('A♠', Karty::label(0));
        self::assertSame('K♠', Karty::label(12));
        self::assertSame('A♥', Karty::label(13));
        self::assertSame('10♦', Karty::label(9 + 26));
    }

    /** Kier i karo czerwone, pik i trefl czarne - do koloru na ekranie. */
    public function testRedSuitsAreRecognised(): void
    {
        self::assertFalse(Karty::isRed(0));   // pik
        self::assertTrue(Karty::isRed(13));   // kier
        self::assertTrue(Karty::isRed(26));   // karo
        self::assertFalse(Karty::isRed(39));  // trefl
    }
}
