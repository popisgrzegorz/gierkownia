<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\Room\RoomManager;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Formularze bez tokenu CSRF (audyt P2-6). SameSite=Lax nie chroni przed
 * innym serwisem pod tym samym site (*.j33.pl, rozne subdomeny) - a to
 * wlasnie klasyczne formularze potrafia wykorzystac przez pelnoekranowe
 * przekierowanie POST-em. Zakres celowo pokrywa formularze HTML (room, admin),
 * nie akcje gry wysylane fetchem (te chroni juz samo SameSite, bo fetch
 * cross-site nie dolacza ciasteczka bez zgody CORS).
 */
final class CsrfProtectionTest extends WebTestCase
{
    private const ADMIN_PASSWORD = 'testowe-haslo-panelu';

    public function testCreatingARoomWithoutATokenIsRejected(): void
    {
        $client = static::createClient();
        $client->request('POST', '/room', ['name' => 'x']);

        self::assertResponseRedirects('/');
    }

    public function testCreatingARoomWithAValidTokenSucceeds(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');
        $token = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/room', ['_csrf_token' => $token]);

        self::assertResponseRedirects();
        self::assertStringStartsWith('/host/', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testResettingARoomWithoutATokenIsRejected(): void
    {
        $client = static::createClient();
        $room = static::getContainer()->get(RoomManager::class)->create();
        $client->request('GET', "/host/{$room->code}?t={$room->hostToken}");

        $client->request('POST', "/room/{$room->code}/reset");

        self::assertResponseStatusCodeSame(403);
    }

    public function testStoppingAGameWithoutATokenIsRejected(): void
    {
        $client = static::createClient();
        $room = static::getContainer()->get(RoomManager::class)->create();
        $client->request('GET', "/host/{$room->code}?t={$room->hostToken}");

        $client->request('POST', "/room/{$room->code}/game/stop");

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCreatingACategoryWithoutATokenIsRejected(): void
    {
        $client = static::createClient();
        $this->logInAsAdmin($client);

        $client->request('POST', '/admin/kategoria', ['name' => 'Test', 'kind' => 'hasla']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCreatingACategoryWithAValidTokenSucceeds(): void
    {
        $client = static::createClient();
        $crawler = $this->logInAsAdmin($client);
        $token = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/admin/kategoria', [
            'name' => 'Test CSRF',
            'kind' => 'hasla',
            '_csrf_token' => $token,
        ]);

        self::assertResponseRedirects();
    }

    /**
     * IP losowe: limiter logowania (P2-7) zyje w pliku na dysku i przezywa
     * miedzy osobnymi uruchomieniami PHPUnit - dzielony adres dawalby
     * falszywe 429 po kilku kolejnych przebiegach tego pliku testow.
     */
    private function logInAsAdmin(KernelBrowser $client): \Symfony\Component\DomCrawler\Crawler
    {
        $ip = sprintf('10.%d.%d.%d', random_int(1, 254), random_int(1, 254), random_int(1, 254));

        $crawler = $client->request('GET', '/admin/login', server: ['REMOTE_ADDR' => $ip]);
        $client->submitForm('Wejdź', ['password' => self::ADMIN_PASSWORD], 'POST', ['REMOTE_ADDR' => $ip]);

        // Kategorie tez maja formularz z tym samym tokenem "admin" - wystarczy
        // wyciagnac go z listy kategorii zamiast osobno z ekranu logowania,
        // zeby uzyc go po zalogowaniu (token pozostaje wazny w tej samej sesji).
        return $client->request('GET', '/admin', server: ['REMOTE_ADDR' => $ip]);
    }
}
