<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Bez jawnego ustawienia strefy PHP liczy wszystko w UTC (domyslne, gdy
 * date.timezone nie jest ustawione w php.ini serwera) - daty zapisanych
 * turniejow (filtr Twig `date`, TournamentArchive::save) wychodzily
 * przesuniete o 1-2h wzgledem prawdziwej pory imprezy. Kernel::__construct
 * ustawia strefe programowo, wiec dziala identycznie na kazdej maszynie
 * bez recznej konfiguracji php.ini.
 */
final class KernelTimezoneTest extends KernelTestCase
{
    public function testApplicationRunsInWarsawTimeNotUtc(): void
    {
        self::bootKernel();

        self::assertSame('Europe/Warsaw', date_default_timezone_get());
    }
}
