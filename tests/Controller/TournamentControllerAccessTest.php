<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Turniej: lista zapisow i wznowienie nie maja pokoju do sprawdzenia przez
 * RoomAccess, wiec bez wlasnej bramy kazdy anonim widzialby nazwy druzyn
 * z minionych imprez i mogl jednym POST-em wznowic cudzy turniej, stajac sie
 * jego hostem. To dokladnie ta dziura, ktora audyt znalazl na produkcji -
 * ten test ma pilnowac, zeby nie wrocila po nastepnym refaktorze.
 */
final class TournamentControllerAccessTest extends WebTestCase
{
    /** Ustawione w config/services_test.yaml - niezalezne od lokalnego .env.local. */
    private const ADMIN_PASSWORD = 'testowe-haslo-panelu';

    public function testAnonymousCannotSeeTheListOfSavedTournaments(): void
    {
        $client = static::createClient();
        $client->request('GET', '/turnieje');

        self::assertResponseRedirects('/admin/login');
    }

    public function testAnonymousCannotRestoreASavedTournament(): void
    {
        $client = static::createClient();
        // Id nie musi istniec - brama ma zatrzymac zadanie, zanim cokolwiek
        // sprobuje odczytac z dysku.
        $client->request('POST', '/turnieje/nieistniejacyZapis/wznow');

        self::assertResponseRedirects('/admin/login');
    }

    public function testLoggedInAdminCanSeeTheList(): void
    {
        $client = static::createClient();
        $this->logIn($client);

        $client->request('GET', '/turnieje');

        self::assertResponseIsSuccessful();
    }

    public function testLoggedInAdminReachesTheRestoreAction(): void
    {
        $client = static::createClient();
        $token = $this->logIn($client);

        // Zalogowany admin przechodzi bramę - trafia w logikę "nie ma takiego
        // zapisu" (flash + redirect na liste), a nie w przekierowanie na
        // logowanie. To odróżnia "brama zamknięta" od "zapis nie istnieje".
        $client->request('POST', '/turnieje/nieistniejacyZapis/wznow', ['_csrf_token' => $token]);

        self::assertResponseRedirects('/turnieje');
    }

    /** Bez wazengo tokenu CSRF wznowienie nie przechodzi, nawet dla zalogowanego admina. */
    public function testLoggedInAdminWithoutACsrfTokenIsRejected(): void
    {
        $client = static::createClient();
        $this->logIn($client);

        $client->request('POST', '/turnieje/nieistniejacyZapis/wznow');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Loguje i zwraca token CSRF ("admin") wyciagniety ze strony logowania -
     * ten sam token zostaje wazny w sesji, wiec mozna go reuzyc do kolejnych
     * zadan. Manager tokenow nie dziala poza aktywnym zadaniem, wiec token
     * trzeba wziac z realnie wyrenderowanej strony, a nie z kontenera wprost.
     *
     * IP jest losowe: limiter logowania (P2-7) zyje w pliku na dysku i
     * przezywa miedzy osobnymi uruchomieniami PHPUnit - dzielony, stary adres
     * dawalby falszywe 429 po kilku kolejnych przebiegach tego pliku testow.
     */
    private function logIn(KernelBrowser $client): string
    {
        $ip = sprintf('10.%d.%d.%d', random_int(1, 254), random_int(1, 254), random_int(1, 254));

        $crawler = $client->request('GET', '/admin/login', server: ['REMOTE_ADDR' => $ip]);
        $token = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');

        $client->submitForm('Wejdź', ['password' => self::ADMIN_PASSWORD], 'POST', ['REMOTE_ADDR' => $ip]);

        return $token;
    }
}
