<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\Room\RoomManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Limity prob per IP (P2-6 audytu). Kazdy test uzywa wlasnego, losowego IP
 * klienta - liczniki zyja w cache.app na dysku i przezywaja miedzy uruchomieniami
 * testow, wiec dzielony adres rozjechalby wyniki w zaleznosci od kolejnosci
 * odpalania testow.
 */
final class RateLimitTest extends WebTestCase
{
    private const ADMIN_PASSWORD = 'testowe-haslo-panelu';

    public function testLoginIsBlockedAfterTooManyWrongPasswords(): void
    {
        $client = static::createClient();
        $ip = $this->randomIp();

        for ($i = 0; $i < 10; ++$i) {
            $crawler = $client->request('GET', '/admin/login', server: ['REMOTE_ADDR' => $ip]);
            $token = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');
            $client->request('POST', '/admin/login', ['password' => 'zle-haslo', '_csrf_token' => $token], [], ['REMOTE_ADDR' => $ip]);
        }

        // Limit wyczerpany - kolejna proba nie trafia juz w AdminAccess::login(),
        // nawet gdyby ktos w tej jednej z 11 prob w koncu odgadl haslo.
        $crawler = $client->request('GET', '/admin/login', server: ['REMOTE_ADDR' => $ip]);
        $token = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/admin/login', ['password' => self::ADMIN_PASSWORD, '_csrf_token' => $token], [], ['REMOTE_ADDR' => $ip]);

        self::assertResponseRedirects('/admin/login');
        $client->followRedirect();
        self::assertStringContainsString('Zbyt wiele prob logowania', (string) $client->getResponse()->getContent());
    }

    public function testJoiningIsBlockedAfterTooManyAttempts(): void
    {
        $client = static::createClient();
        $ip = $this->randomIp();
        $room = static::getContainer()->get(RoomManager::class)->create();

        // Kazda iteracja to inny telefon (nowe ciasteczka), ale to samo IP -
        // symuluje skrypt zasypujacy pokoj z jednego adresu, a nie jednego
        // gracza, ktory po dolaczeniu i tak przekierowuje sie dalej.
        for ($i = 0; $i < 30; ++$i) {
            $client->getCookieJar()->clear();
            $crawler = $client->request('GET', "/play/{$room->code}", server: ['REMOTE_ADDR' => $ip]);
            $token = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');
            $client->request('POST', "/room/{$room->code}/join", ['name' => "Ekipa{$i}", '_csrf_token' => $token], [], ['REMOTE_ADDR' => $ip]);
        }

        $client->getCookieJar()->clear();
        $crawler = $client->request('GET', "/play/{$room->code}", server: ['REMOTE_ADDR' => $ip]);
        $token = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');
        $client->request('POST', "/room/{$room->code}/join", ['name' => 'Jeszcze jedna', '_csrf_token' => $token], [], ['REMOTE_ADDR' => $ip]);

        self::assertResponseRedirects("/play/{$room->code}");
        self::assertCount(30, static::getContainer()->get(RoomManager::class)->teams($room->code));
    }

    public function testRoomStateEnumerationIsRateLimited(): void
    {
        $client = static::createClient();
        $ip = $this->randomIp();
        $room = static::getContainer()->get(RoomManager::class)->create();

        for ($i = 0; $i < 60; ++$i) {
            $client->request('GET', "/api/room/{$room->code}/state", server: ['REMOTE_ADDR' => $ip]);
            self::assertResponseIsSuccessful();
        }

        $client->request('GET', "/api/room/{$room->code}/state", server: ['REMOTE_ADDR' => $ip]);

        self::assertResponseStatusCodeSame(429);
    }

    private function randomIp(): string
    {
        return sprintf('10.%d.%d.%d', random_int(1, 254), random_int(1, 254), random_int(1, 254));
    }
}
