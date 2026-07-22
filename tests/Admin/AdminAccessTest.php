<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\AdminAccess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class AdminAccessTest extends TestCase
{
    private const PASSWORD = 'tajne-haslo';

    /**
     * Panel bez hasla musi byc zamkniety, a nie otwarty. Domyslnie pusta
     * konfiguracja w repozytorium jest bezpieczna tylko wtedy, gdy blokuje wejscie.
     */
    public function testPanelWithoutAPasswordIsDisabled(): void
    {
        $access = new AdminAccess('');
        $request = $this->request();

        self::assertFalse($access->isEnabled());
        self::assertFalse($access->login($request, ''));
        self::assertFalse($access->login($request, 'cokolwiek'));
        self::assertFalse($access->isLoggedIn($request));
    }

    public function testWhitespaceOnlyPasswordCountsAsNoPassword(): void
    {
        self::assertFalse((new AdminAccess("   \n"))->isEnabled());
    }

    public function testCorrectPasswordLetsIn(): void
    {
        $access = new AdminAccess(self::PASSWORD);
        $request = $this->request();

        self::assertTrue($access->login($request, self::PASSWORD));
        self::assertTrue($access->isLoggedIn($request));
    }

    public function testWrongPasswordDoesNotLetIn(): void
    {
        $access = new AdminAccess(self::PASSWORD);
        $request = $this->request();

        self::assertFalse($access->login($request, 'tajne-hasl'));
        self::assertFalse($access->login($request, 'TAJNE-HASLO'));
        self::assertFalse($access->isLoggedIn($request));
    }

    public function testLogoutClosesTheDoor(): void
    {
        $access = new AdminAccess(self::PASSWORD);
        $request = $this->request();
        $access->login($request, self::PASSWORD);

        $access->logout($request);

        self::assertFalse($access->isLoggedIn($request));
    }

    /**
     * Sesja zalozona przed logowaniem nie moze zostac ta sama - inaczej ktos,
     * kto podsunie ofierze swoj identyfikator sesji, wejdzie do panelu razem z nia.
     */
    public function testSessionIdentifierChangesOnLogin(): void
    {
        $access = new AdminAccess(self::PASSWORD);
        $request = $this->request();
        $session = $request->getSession();
        $session->set('cokolwiek', 1);
        $before = $session->getId();

        $access->login($request, self::PASSWORD);

        self::assertNotSame($before, $session->getId());
    }

    public function testRequestWithoutSessionIsNotLoggedIn(): void
    {
        $access = new AdminAccess(self::PASSWORD);

        self::assertFalse($access->isLoggedIn(Request::create('/admin')));
    }

    private function request(): Request
    {
        $request = Request::create('/admin');
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $request->setSession($session);

        return $request;
    }
}
