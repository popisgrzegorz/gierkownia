<?php

declare(strict_types=1);

namespace App\Core\Room;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Kto jest kim w pokoju: hostToken (ekran TV) i deviceToken (telefon druzyny).
 *
 * Tokeny sa nieprzezroczyste i weryfikowane przy Redisie, a nie podpisane jak JWT
 * ze spec 4. Powod: stan pokoju i tak zyje w Redisie z TTL, wiec ten sam odczyt
 * daje uniewaznienie tokenu przy resecie za darmo - JWT trzeba by osobno
 * odwolywac, a klucze roznosic po maszynach. Do wpiecia Lexika wystarczy podmienic
 * ten jeden serwis (patrz decyzja w README).
 *
 * Klucze sa per pokoj, wiec jeden telefon moze byc w kilku pokojach naraz
 * i nie gubi tozsamosci po przelaczeniu.
 */
final readonly class RoomAccess
{
    private const HOST_SESSION_KEY = 'host_tokens';
    private const TEAM_COOKIE_PREFIX = 'gierki_team_';

    public function __construct(
        private RoomManager $rooms,
        private int $roomTtl,
    ) {
    }

    /** Zapamietuje hosta w sesji przegladarki, ktora utworzyla pokoj. */
    public function rememberHost(Request $request, string $code, string $hostToken): void
    {
        $session = $request->getSession();
        /** @var array<string, string> $tokens */
        $tokens = $session->get(self::HOST_SESSION_KEY, []);
        $tokens[$code] = $hostToken;
        $session->set(self::HOST_SESSION_KEY, $tokens);
    }

    public function hostToken(Request $request, string $code): ?string
    {
        if (!$request->hasSession()) {
            return null;
        }

        /** @var array<string, string> $tokens */
        $tokens = $request->getSession()->get(self::HOST_SESSION_KEY, []);

        return $tokens[$code] ?? null;
    }

    public function isHost(Request $request, string $code): bool
    {
        $token = $this->hostToken($request, $code) ?? (string) $request->headers->get('X-Host-Token', '');

        return '' !== $token && $this->rooms->isHostToken($code, $token);
    }

    public function requireHost(Request $request, string $code): void
    {
        if (!$this->isHost($request, $code)) {
            throw new AccessDeniedHttpException('Ta akcja jest dostepna tylko dla hosta pokoju.');
        }
    }

    /** Zapisuje token urzadzenia w ciasteczku - telefon nie musi go pamietac sam. */
    public function rememberTeam(Response $response, string $code, string $deviceToken): void
    {
        $response->headers->setCookie(Cookie::create(
            name: self::TEAM_COOKIE_PREFIX.$code,
            value: $deviceToken,
            expire: time() + $this->roomTtl,
            path: '/',
            secure: false, // LAN po HTTP; przy HTTPS (PWA) ustawic na true
            httpOnly: true,
            sameSite: Cookie::SAMESITE_LAX,
        ));
    }

    /**
     * Rozpoznaje druzyne po ciasteczku, a jesli go nie ma - po tokenie
     * z naglowka (dla klientow innych niz przegladarka, np. przyciskow ESP32).
     */
    public function team(Request $request, string $code): ?Team
    {
        $token = (string) $request->cookies->get(self::TEAM_COOKIE_PREFIX.$code, '');

        if ('' === $token) {
            $token = (string) $request->headers->get('X-Device-Token', '');
        }

        return '' === $token ? null : $this->rooms->teamByDeviceToken($code, $token);
    }

    public function requireTeam(Request $request, string $code): Team
    {
        return $this->team($request, $code)
            ?? throw new AccessDeniedHttpException('Nie rozpoznano druzyny - dolacz do pokoju ponownie.');
    }

    public function forgetTeam(Response $response, string $code): void
    {
        $response->headers->clearCookie(self::TEAM_COOKIE_PREFIX.$code);
    }
}
