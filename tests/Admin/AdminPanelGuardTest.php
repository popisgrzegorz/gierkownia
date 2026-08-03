<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\AdminAccess;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Brama do /panel: to samo haslo i ta sama sesja co stary /admin. Kluczowe
 * jest, zeby przekierowania wskazywaly na logowanie, a NIE na trasy starego
 * panelu ("admin_categories") - te znikna razem z nim (krok 7 migracji),
 * a martwy fallback wywalilby sie na RouteNotFoundException zamiast
 * pokazac ekran logowania.
 */
final class AdminPanelGuardTest extends WebTestCase
{
    private const PASSWORD = 'panel-test-haslo';

    public function testAnonymousGetToThePanelRedirectsToLoginWithNext(): void
    {
        $client = $this->clientWithPassword();

        $client->request('GET', '/panel/category');

        self::assertResponseRedirects('/admin/login?next=/panel/category');
    }

    public function testAnonymousPostToThePanelRedirectsToLoginWithoutNext(): void
    {
        $client = $this->clientWithPassword();

        $client->request('POST', '/panel/category/new');

        self::assertResponseRedirects('/admin/login');
    }

    /**
     * Puste haslo oznacza "panel jeszcze nie skonfigurowany", nie "otwarty dla
     * wszystkich" (AdminAccess jest fail-closed, w odroznieniu od HostAccess) -
     * powinno wciaz przekierowac na /admin/login, ktory pokaze ekran
     * "panel wylaczony" zamiast martwej trasy starego panelu (500).
     */
    public function testDisabledGateStillRedirectsToLoginNotToTheOldPanelRoute(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set(AdminAccess::class, new AdminAccess(''));

        $client->request('GET', '/panel/category');

        self::assertResponseRedirects('/admin/login');
    }

    private function clientWithPassword(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set(AdminAccess::class, new AdminAccess(self::PASSWORD));

        return $client;
    }
}
