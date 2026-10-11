<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Produkcja instaluje zaleznosci przez `composer install --no-dev`. Pakiet
 * uzywany w kodzie aplikacji, a wpisany tylko do require-dev (albo dociagany
 * wylacznie przez inny pakiet dev), znika tam bez ostrzezenia - a gdy kod
 * lapie \Throwable, objawia sie to cicha usterka, nie bledem. Tak wlasnie
 * "Ostatnia litera" na serwerze odrzucala kazde slowo: HunspellWordJudge
 * uzywa symfony/process, ktory siedzial tylko przy maker-bundle.
 */
final class ComposerRuntimeDependenciesTest extends TestCase
{
    /** Pakiety, bez ktorych cos w src/ cicho przestaje dzialac. */
    private const REQUIRED_AT_RUNTIME = [
        'symfony/process', // HunspellWordJudge
        'symfony/http-client', // DeezerClient
    ];

    public function testPackagesUsedBySourceCodeAreNotDevOnly(): void
    {
        $composer = json_decode((string) file_get_contents(\dirname(__DIR__).'/composer.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);

        $require = $composer['require'] ?? [];
        self::assertIsArray($require);

        foreach (self::REQUIRED_AT_RUNTIME as $package) {
            self::assertArrayHasKey($package, $require, sprintf('"%s" musi byc w require, nie tylko w require-dev.', $package));
        }
    }
}
