<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Admin\AdminAccess;
use App\Core\Room\RoomManager;
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

    /**
     * Panel /panel (EasyAdmin) chroni formularze wlasnym CSRF-em Symfony Form
     * (ten sam mechanizm, ktory chroni kazdy inny formularz w aplikacji) -
     * nie doszywalismy tu niczego recznie, ale warto miec prawdziwy test HTTP
     * zamiast polegac wylacznie na czytaniu zrodel EasyAdmina.
     */
    public function testPanelCreatingACategoryWithoutATokenIsRejected(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $ip = sprintf('10.%d.%d.%d', random_int(1, 254), random_int(1, 254), random_int(1, 254));
        static::getContainer()->set(AdminAccess::class, new AdminAccess(self::ADMIN_PASSWORD));

        $client->request('GET', '/admin/login', server: ['REMOTE_ADDR' => $ip]);
        $client->submitForm('Wejdź', ['password' => self::ADMIN_PASSWORD], 'POST', ['REMOTE_ADDR' => $ip]);

        $client->request('GET', '/panel/category/new');
        $client->request('POST', '/panel/category/new', [
            'Category' => ['name' => 'Bez tokenu', 'kind' => 'hasla', '_token' => 'zly-token'],
        ]);

        self::assertResponseStatusCodeSame(422);
    }
}
