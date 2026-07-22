<?php

declare(strict_types=1);

namespace App\Tests\Core\Http;

use App\Core\Http\ApiFormatSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApiFormatSubscriberTest extends TestCase
{
    #[DataProvider('paths')]
    public function testRecognisesApiPaths(string $path, bool $expected): void
    {
        self::assertSame($expected, ApiFormatSubscriber::isApiPath($path));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function paths(): iterable
    {
        yield 'stan pokoju' => ['/api/room/ABCD/state', true];
        yield 'akcja gry' => ['/room/ABCD/game/action', true];
        yield 'punkty' => ['/room/ABCD/game/score', true];
        yield 'sam prefiks gry' => ['/room/ABCD/game', true];

        // Ekrany maja pozostac HTML-em - inaczej host zamiast planszy dostalby JSON.
        yield 'ekran hosta' => ['/host/ABCD', false];
        yield 'sterownik telefonu' => ['/play/ABCD/controller', false];
        yield 'dolaczenie druzyny' => ['/room/ABCD/join', false];
        yield 'strona startowa' => ['/', false];

        // Kod pokoju to zawsze cztery wielkie litery; cokolwiek innego nie jest
        // nasza trasa i nie ma powodu zmieniac mu formatu.
        yield 'kod malymi literami' => ['/room/abcd/game/action', false];
        yield 'kod za dlugi' => ['/room/ABCDE/game/action', false];
    }
}
