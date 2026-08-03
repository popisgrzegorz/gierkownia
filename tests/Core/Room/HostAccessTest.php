<?php

declare(strict_types=1);

namespace App\Tests\Core\Room;

use App\Core\Room\HostAccess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class HostAccessTest extends TestCase
{
    private const PASSWORD = 'tajne-haslo-hosta';

    /**
     * Bez HOST_PASSWORD zakladanie pokoju musi zostac otwarte jak dotychczas -
     * inaczej ustawienie tej funkcji domyslnie zablokowaloby hostowanie
     * na kazdej juz dzialajacej instalacji.
     */
    public function testWithoutAPasswordTheGateStaysOpen(): void
    {
        $access = new HostAccess('');
        $request = $this->request();

        self::assertFalse($access->isEnabled());
        self::assertTrue($access->isLoggedIn($request));
        self::assertFalse($access->needsLogin($request));
        self::assertFalse($access->login($request, 'cokolwiek'));
    }

    public function testWhitespaceOnlyPasswordCountsAsNoPassword(): void
    {
        self::assertFalse((new HostAccess("   \n"))->isEnabled());
    }

    public function testWithAPasswordTheGateBlocksUntilLogin(): void
    {
        $access = new HostAccess(self::PASSWORD);
        $request = $this->request();

        self::assertTrue($access->isEnabled());
        self::assertTrue($access->needsLogin($request));

        self::assertTrue($access->login($request, self::PASSWORD));

        self::assertFalse($access->needsLogin($request));
        self::assertTrue($access->isLoggedIn($request));
    }

    public function testWrongPasswordDoesNotLetIn(): void
    {
        $access = new HostAccess(self::PASSWORD);
        $request = $this->request();

        self::assertFalse($access->login($request, 'tajne-haslo-host'));
        self::assertFalse($access->login($request, 'TAJNE-HASLO-HOSTA'));
        self::assertTrue($access->needsLogin($request));
    }

    /**
     * Sesja zalozona przed logowaniem nie moze zostac ta sama - inaczej ktos,
     * kto podsunie ofierze swoj identyfikator sesji, zostalby hostem razem z nia.
     */
    public function testSessionIdentifierChangesOnLogin(): void
    {
        $access = new HostAccess(self::PASSWORD);
        $request = $this->request();
        $session = $request->getSession();
        $session->set('cokolwiek', 1);
        $before = $session->getId();

        $access->login($request, self::PASSWORD);

        self::assertNotSame($before, $session->getId());
    }

    private function request(): Request
    {
        $request = Request::create('/');
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $request->setSession($session);

        return $request;
    }
}
