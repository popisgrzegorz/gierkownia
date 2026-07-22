<?php

declare(strict_types=1);

namespace App\Tests\Core\Game;

use App\Core\Game\GameRegistry;
use App\Core\Game\UnknownGameException;
use App\Tests\Fixtures\AlfaGame;
use App\Tests\Fixtures\BetaGame;
use PHPUnit\Framework\TestCase;

final class GameRegistryTest extends TestCase
{
    public function testFindsGameByItsType(): void
    {
        $alfa = new AlfaGame();
        $registry = new GameRegistry([$alfa, new BetaGame()]);

        self::assertTrue($registry->has('alfa'));
        self::assertFalse($registry->has('kalambury'));
        self::assertSame($alfa, $registry->get('alfa'));
        self::assertSame(['alfa', 'beta'], $registry->types());
    }

    public function testUnknownTypeListsWhatIsAvailable(): void
    {
        $registry = new GameRegistry([new AlfaGame()]);

        $this->expectException(UnknownGameException::class);
        $this->expectExceptionMessageMatches('/Nie znam gry "kalambury".*alfa/s');

        $registry->get('kalambury');
    }

    public function testEmptyRegistrySaysSoInsteadOfListingNothing(): void
    {
        $registry = new GameRegistry([]);

        $this->expectException(UnknownGameException::class);
        $this->expectExceptionMessageMatches('/brak zarejestrowanych modulow/');

        $registry->get('melodia');
    }

    /**
     * Dwie gry pod jednym typem znaczylyby, ze rdzen laduje losowa z nich -
     * i to inna w zaleznosci od kolejnosci uslug w kontenerze. Lepiej wywalic
     * sie od razu niz raz na jakis czas w trakcie imprezy.
     */
    public function testDuplicateTypeIsRejected(): void
    {
        $registry = new GameRegistry([new AlfaGame(), new AlfaGame()]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Typ gry "alfa" jest zajety/');

        $registry->types();
    }
}
