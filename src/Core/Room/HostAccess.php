<?php

declare(strict_types=1);

namespace App\Core\Room;

use Symfony\Component\HttpFoundation\Request;

/**
 * Haslo do ZAKLADANIA pokoju - osobne od tokenu hosta (RoomAccess) i od hasla
 * panelu tresci (AdminAccess). Gra stoi pod publiczna domena, wiec bez tego
 * kazdy, kto trafi na strone glowna, mogl zalozyc pokoj i stac sie hostem.
 *
 * Sprawdzane WYLACZNIE przy "Nowa gra" na stronie glownej - raz zapamietane
 * w sesji przegladarki, nie pyta ponownie przy kolejnych pokojach ani przy
 * powrocie na juz zalozony ekran hosta (ten pilnuje osobny token z RoomAccess).
 *
 * Puste haslo w konfiguracji wylacza bramke calkowicie - tak samo jak
 * w AdminAccess, zeby domyslny stan (przed ustawieniem HOST_PASSWORD) nie
 * blokowal hostowania na nowej maszynie czy w czystym LAN.
 */
final readonly class HostAccess
{
    private const SESSION_KEY = 'host_authenticated';

    public function __construct(
        private string $hostPassword,
    ) {
    }

    public function isEnabled(): bool
    {
        return '' !== trim($this->hostPassword);
    }

    /** hash_equals zamiast === - czas porownania nie ma zdradzac, ile znakow sie zgadza. */
    public function login(Request $request, string $password): bool
    {
        if (!$this->isEnabled() || !hash_equals($this->hostPassword, $password)) {
            return false;
        }

        $session = $request->getSession();
        $session->migrate(true);
        $session->set(self::SESSION_KEY, true);

        return true;
    }

    public function isLoggedIn(Request $request): bool
    {
        return !$this->isEnabled()
            || ($request->hasSession() && true === $request->getSession()->get(self::SESSION_KEY));
    }

    public function needsLogin(Request $request): bool
    {
        return !$this->isLoggedIn($request);
    }
}
