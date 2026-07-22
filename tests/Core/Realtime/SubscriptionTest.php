<?php

declare(strict_types=1);

namespace App\Tests\Core\Realtime;

use App\Core\Realtime\Subscription;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\Jwt\LcobucciFactory;

final class SubscriptionTest extends TestCase
{
    private const SECRET = 'sekret-testowy-ktory-jest-odpowiednio-dlugi';

    private Subscription $subscription;

    protected function setUp(): void
    {
        $this->subscription = new Subscription(new LcobucciFactory(self::SECRET), 3010, 3600);
    }

    /**
     * Adres huba musi isc za hostem zadania. Telewizor otwiera gre po adresie
     * LAN, a laptop przy pisaniu kodu po localhost - wpisany na sztywno jeden
     * z nich zrywa polaczenie SSE u drugiego.
     */
    public function testHubAddressFollowsTheHostTheGameWasOpenedFrom(): void
    {
        $fromTv = Request::create('http://192.168.57.3:8210/host/ABCD');
        $fromLaptop = Request::create('http://localhost:8210/host/ABCD');

        self::assertStringStartsWith(
            'http://192.168.57.3:3010/.well-known/mercure?',
            $this->subscription->url($fromTv, ['room/ABCD']),
        );
        self::assertStringStartsWith(
            'http://localhost:3010/.well-known/mercure?',
            $this->subscription->url($fromLaptop, ['room/ABCD']),
        );
    }

    /**
     * Hub za tym samym reverse proxy co gra: adres musi wyjsc bez portu,
     * czyli w tym samym originie. Doklejony port zrobilby z SSE zapytanie
     * cross-origin, a wtedy przegladarka nie dolaczy ciasteczka z tokenem
     * subskrypcji i prywatne topici (podglad hasla, slowo dla aktora) milkna.
     */
    public function testHubSharesTheAppAddressWhenItSitsBehindTheSameProxy(): void
    {
        $sameOrigin = new Subscription(new LcobucciFactory(self::SECRET), 0, 3600);

        self::assertStringStartsWith(
            'https://gierki.lan/.well-known/mercure?',
            $sameOrigin->url(Request::create('https://gierki.lan/host/ABCD'), ['room/ABCD']),
        );
    }

    /**
     * Za proxy TLS konczy sie na proxy, a do PHP wchodzi zwykly HTTP. Adres
     * huba ma isc za oryginalnym zadaniem, nie za tym polaczeniem: inaczej
     * strona otwarta po HTTPS dostaje adres http://, przegladarka blokuje SSE
     * jako mixed content i gra zamiera na nieaktualnym tablo - bez sladu bledu
     * na ekranie, bo EventSource po prostu nigdy sie nie otwiera.
     */
    public function testHubAddressKeepsTheSchemeTheBrowserActuallyUsed(): void
    {
        $sameOrigin = new Subscription(new LcobucciFactory(self::SECRET), 0, 3600);

        Request::setTrustedProxies(
            ['10.0.7.3'],
            Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT,
        );

        try {
            $behindProxy = Request::create('http://10.0.7.1:8210/host/ABCD', server: [
                'REMOTE_ADDR' => '10.0.7.3',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'HTTP_X_FORWARDED_HOST' => 'gierkownia.j33.pl',
            ]);

            self::assertStringStartsWith(
                'https://gierkownia.j33.pl/.well-known/mercure?',
                $sameOrigin->url($behindProxy, ['room/ABCD']),
            );
        } finally {
            // Zaufane proxy to stan globalny klasy Request - zostawione
            // ustawione przeciekloby do kolejnych testow.
            Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        }
    }

    public function testEveryTopicLandsInTheQueryString(): void
    {
        $url = $this->subscription->url(
            Request::create('http://localhost:8210/host/ABCD'),
            ['room/ABCD', 'room/ABCD/host'],
        );

        self::assertStringContainsString('topic=room%2FABCD&topic=room%2FABCD%2Fhost', $url);
    }

    /**
     * Token jest jedynym, co dzieli telefony od cudzych prywatnych topikow -
     * musi obejmowac dokladnie to, o co poprosil kontroler, i nic wiecej.
     */
    public function testTokenGrantsExactlyTheRequestedTopics(): void
    {
        $topics = ['room/ABCD', 'room/ABCD/team/team-7'];

        $cookie = $this->subscription->cookie(Request::create('http://localhost:8210/'), $topics);

        $claims = $this->claims($cookie->getValue() ?? '');

        self::assertSame('mercureAuthorization', $cookie->getName());
        self::assertSame($topics, $claims['subscribe'] ?? null);
        // Pusta lista selektorow = zadnych praw do publikacji. Publikuje tylko backend.
        self::assertSame([], $claims['publish'] ?? null);
    }

    /**
     * Ciasteczko z tokenem subskrypcji nie moze byc czytelne dla skryptow -
     * inaczej dowolny kod na stronie wyciagnie je i podsluchaduje cudze topici.
     */
    public function testTokenCookieIsNotReadableFromJavaScript(): void
    {
        $cookie = $this->subscription->cookie(Request::create('http://localhost:8210/'), ['room/ABCD']);

        self::assertTrue($cookie->isHttpOnly());
        self::assertNull($cookie->getDomain(), 'Ciasteczko ma byc wazne tylko dla adresu, spod ktorego gra jest otwarta.');
    }

    public function testCookieIsMarkedSecureOnlyWhenTheConnectionIs(): void
    {
        $plain = $this->subscription->cookie(Request::create('http://localhost:8210/'), ['room/ABCD']);
        $tls = $this->subscription->cookie(Request::create('https://gierki.lan/'), ['room/ABCD']);

        self::assertFalse($plain->isSecure(), 'W LAN po HTTP flaga secure zablokowalaby ciasteczko.');
        self::assertTrue($tls->isSecure());
    }

    /**
     * @return array<string, mixed>
     */
    private function claims(string $jwt): array
    {
        $parts = explode('.', $jwt);
        self::assertCount(3, $parts, 'To nie wyglada na JWT.');

        $decoded = base64_decode(strtr($parts[1], '-_', '+/'), true);
        self::assertIsString($decoded, 'Srodkowa czesc JWT nie jest poprawnym base64url.');

        $payload = json_decode($decoded, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertArrayHasKey('mercure', $payload);
        self::assertIsArray($payload['mercure']);

        /** @var array<string, mixed> $mercure */
        $mercure = $payload['mercure'];

        return $mercure;
    }
}
