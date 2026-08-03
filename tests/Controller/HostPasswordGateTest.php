<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\Room\HostAccess;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Bramka na ZALOZENIE pokoju (HOST_PASSWORD) - domyslnie wylaczona (puste
 * haslo w .env), wiec caly reszta testow tworzacych pokoj przez POST /room
 * dziala bez zadnej zmiany. Te testy naduzywaja kontenera testowego, zeby
 * podmienic HostAccess na wersje z WLACZONYM haslem tylko na czas jednego
 * przypadku - .env.local developera nie ma tu znaczenia.
 */
final class HostPasswordGateTest extends WebTestCase
{
    private const PASSWORD = 'sezamie-otworz-sie';

    public function testCreatingARoomWithoutTheHostPasswordIsRejected(): void
    {
        $client = static::createClient();
        // Kernel reboot per zadanie (domyslne zachowanie KernelBrowser) kasuje
        // podmiane serwisu - bez disableReboot() drugie zadanie widzialoby
        // znowu oryginalny, wylaczony HostAccess z .env.
        $client->disableReboot();
        static::getContainer()->set(HostAccess::class, new HostAccess(self::PASSWORD));

        $crawler = $client->request('GET', '/');
        $token = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');

        // Formularz musi w ogole pokazac pole hasla, gdy bramka jest wlaczona.
        self::assertCount(1, $crawler->filter('input[name="hostPassword"]'));

        $client->request('POST', '/room', ['_csrf_token' => $token]);

        self::assertResponseRedirects('/');
        $client->followRedirect();
        self::assertStringContainsString('Nieprawidłowe hasło', (string) $client->getResponse()->getContent());
    }

    public function testCreatingARoomWithTheWrongHostPasswordIsRejected(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set(HostAccess::class, new HostAccess(self::PASSWORD));

        $crawler = $client->request('GET', '/');
        $token = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/room', ['_csrf_token' => $token, 'hostPassword' => 'zle-haslo']);

        self::assertResponseRedirects('/');
    }

    /**
     * Poprawne haslo wpuszcza i zapamietuje w sesji - drugi pokoj z tej samej
     * przegladarki zaklada sie juz bez ponownego pytania (HostAccess).
     */
    public function testCorrectHostPasswordIsRememberedForTheNextRoom(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set(HostAccess::class, new HostAccess(self::PASSWORD));

        $crawler = $client->request('GET', '/');
        $token = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/room', ['_csrf_token' => $token, 'hostPassword' => self::PASSWORD]);
        self::assertResponseRedirects();
        self::assertStringStartsWith('/host/', (string) $client->getResponse()->headers->get('Location'));

        $crawler = $client->request('GET', '/');
        self::assertCount(0, $crawler->filter('input[name="hostPassword"]'), 'Sesja juz pamieta hosta - drugi raz nie powinno pytac.');

        $token = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/room', ['_csrf_token' => $token]);
        self::assertResponseRedirects();
        self::assertStringStartsWith('/host/', (string) $client->getResponse()->headers->get('Location'));
    }
}
