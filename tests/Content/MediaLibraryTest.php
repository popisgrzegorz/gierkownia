<?php

declare(strict_types=1);

namespace App\Tests\Content;

use App\Content\MediaLibrary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sciezki do plikow pochodza z YAML-a pisanego recznie, a potem trafiaja prosto
 * do kontrolera serwujacego pliki. To jedyne miejsce, w ktorym literowka -
 * albo zlosliwy wpis - moglaby wyprowadzic poza katalog mediow.
 */
final class MediaLibraryTest extends TestCase
{
    private const DIR = '/srv/gierki/media';

    #[DataProvider('escapes')]
    public function testPathsEscapingTheMediaDirectoryAreRejected(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new MediaLibrary(self::DIR))->absolutePath($path);
    }

    /** @return iterable<string, array{string}> */
    public static function escapes(): iterable
    {
        yield 'wyjscie w gore' => ['../../etc/passwd'];
        yield 'wyjscie w srodku sciezki' => ['demo/../../etc/passwd'];
        yield 'wyjscie po slashu wiodacym' => ['/../etc/passwd'];
        yield 'ukryte w glebi' => ['a/b/c/../../../../etc/passwd'];
    }

    public function testEmptyPathIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new MediaLibrary(self::DIR))->absolutePath('   /');
    }

    /**
     * Sciezka bezwzgledna z YAML-a nie moze podstawic pliku spoza katalogu -
     * wiodacy ukosnik obcinamy i traktujemy wpis jako wzgledny.
     */
    public function testLeadingSlashIsTreatedAsRelative(): void
    {
        $media = new MediaLibrary(self::DIR);

        self::assertSame(self::DIR.'/etc/passwd', $media->absolutePath('/etc/passwd'));
    }

    public function testNormalPathResolvesInsideTheDirectory(): void
    {
        $media = new MediaLibrary(self::DIR);

        self::assertSame(self::DIR.'/demo/sto-lat.wav', $media->absolutePath('demo/sto-lat.wav'));
    }

    public function testTrailingSlashInTheDirectoryDoesNotDoubleUp(): void
    {
        $media = new MediaLibrary(self::DIR.'/');

        self::assertSame(self::DIR.'/demo/sto-lat.wav', $media->absolutePath('demo/sto-lat.wav'));
    }

    public function testExistsSaysNoInsteadOfThrowingOnABadPath(): void
    {
        $media = new MediaLibrary(self::DIR);

        // Import talii sprawdza istnienie pliku dla kazdej pozycji - zla sciezka
        // ma trafic do raportu "brak pliku", a nie wywalic calego importu.
        self::assertFalse($media->exists('../../etc/passwd'));
        self::assertFalse($media->exists('demo/nie-ma.wav'));
    }

    public function testFindsAFileThatIsReallyThere(): void
    {
        $media = new MediaLibrary(\dirname(__DIR__, 2).'/var/media');

        if (!is_dir($media->directory().'/demo')) {
            self::markTestSkipped('Brak katalogu demo - uruchom generator plikow testowych.');
        }

        self::assertTrue($media->exists('demo/sto-lat.wav'));
    }
}
