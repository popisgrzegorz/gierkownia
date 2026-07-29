<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * base.html.twig konsumowal app.flashes PRZED blokiem body - panel admina mial
 * wlasny, wyglosowany alert Tablera nizej, ale ten zawsze widzial juz pusta
 * liste. Efekt: komunikat ladowal jako goly, nieostylowany akapit nad
 * navbarem (P2-27 audytu). Blok "flashes" w base.html.twig, nadpisany pustym
 * w szablonach admina, ma to naprawic.
 */
final class AdminFlashTest extends WebTestCase
{
    public function testWrongPasswordFlashRendersAsStyledAlertNotBareParagraph(): void
    {
        // IP losowe: limiter logowania (P2-7) zyje w pliku na dysku i przezywa
        // miedzy osobnymi uruchomieniami PHPUnit.
        $ip = sprintf('10.%d.%d.%d', random_int(1, 254), random_int(1, 254), random_int(1, 254));

        $client = static::createClient();
        $crawler = $client->request('GET', '/admin/login', server: ['REMOTE_ADDR' => $ip]);
        $token = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');

        $crawler = $client->request('POST', '/admin/login', ['password' => 'zle-haslo', '_csrf_token' => $token], [], ['REMOTE_ADDR' => $ip]);

        self::assertCount(0, $crawler->filter('p.flash'), 'Flash nie powinien wyjsc jako goly akapit poza layoutem.');
        self::assertGreaterThan(0, $crawler->filter('.alert')->count(), 'Flash powinien wyjsc jako alert Tablera.');
        self::assertStringContainsString('Nieprawidłowe hasło', $crawler->filter('.alert')->text());
    }
}
