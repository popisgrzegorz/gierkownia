<?php

declare(strict_types=1);

namespace App\Core\Realtime;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;

/**
 * Strona kliencka Mercure: adres SSE + ciasteczko autoryzujace subskrypcje.
 *
 * Nie uzywamy Symfony\Component\Mercure\Authorization, bo ono bierze host huba
 * ze sztywnego MERCURE_PUBLIC_URL i odmawia dzialania, gdy host zadania jest
 * inny ("different second-level domain"). W LAN ten sam serwer jest widziany
 * raz jako 192.168.x.x z telewizora, raz jako localhost przy developmencie -
 * wiec adres huba wyprowadzamy z biezacego zadania. Ciasteczko jest host-only,
 * a port nie wplywa ani na przynaleznosc ciasteczka, ani na SameSite, wiec
 * hub na innym porcie tego samego hosta dostaje je bez problemu.
 *
 * Hub stoi w jednym z dwoch miejsc, o czym rozstrzyga MERCURE_PUBLIC_PORT:
 * obok aplikacji na wlasnym porcie (LAN) albo za tym samym reverse proxy,
 * pod ta sama nazwa i sciezka HUB_PATH (wdrozenie po HTTPS).
 */
final readonly class Subscription
{
    private const COOKIE_NAME = 'mercureAuthorization';
    private const HUB_PATH = '/.well-known/mercure';

    public function __construct(
        // Nazwa argumentu wskazuje hub: bundle rejestruje fabryke tokenow
        // per hub, wiec goly TokenFactoryInterface nie da sie zautowirowac.
        private TokenFactoryInterface $defaultFactory,
        private int $mercurePublicPort,
        private int $roomTtl,
    ) {
    }

    /**
     * Adres, pod ktory EventSource w przegladarce podepnie sie po zdarzenia.
     *
     * @param list<string> $topics
     */
    public function url(Request $request, array $topics): string
    {
        $query = implode('&', array_map(
            static fn (string $topic): string => 'topic='.rawurlencode($topic),
            $topics,
        ));

        // Port 0 znaczy "hub pod tym samym adresem co aplikacja" - przepuszcza
        // go to samo proxy, ktore obsluguje gre. getHttpHost() sam pomija port
        // domyslny dla schematu, wiec po HTTPS wychodzi czyste "host", a nie
        // "host:443" - a to wazne, bo adres huba musi trafic w ten sam origin,
        // inaczej przegladarka potraktuje SSE jako zapytanie cross-origin
        // i nie dolaczy ciasteczka z tokenem subskrypcji.
        $authority = 0 === $this->mercurePublicPort
            ? $request->getHttpHost()
            : $request->getHost().':'.$this->mercurePublicPort;

        return sprintf('%s://%s%s?%s', $request->getScheme(), $authority, self::HUB_PATH, $query);
    }

    /**
     * Ciasteczko z JWT ograniczonym do podanych topikow. Hub wpuszcza na
     * prywatne topici (/host, /team/{id}) wylacznie z takim tokenem, wiec
     * telefon jednej druzyny nie podejrzy topiku drugiej.
     *
     * @param list<string> $topics
     */
    public function cookie(Request $request, array $topics): Cookie
    {
        return Cookie::create(
            name: self::COOKIE_NAME,
            // publish: [] - pusta lista selektorow, czyli zero praw do publikacji.
            // Do huba pisze wylacznie backend; gdyby telefon mial ten claim,
            // moglby wystrzelic wlasny event "buzz" albo "score".
            value: $this->defaultFactory->create(subscribe: $topics, publish: []),
            expire: time() + $this->roomTtl,
            path: '/',
            domain: null, // host-only: waznie tylko dla adresu, spod ktorego gra jest otwarta
            secure: $request->isSecure(),
            httpOnly: true,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }
}
