<?php

declare(strict_types=1);

namespace App\Admin;

use Symfony\Component\HttpFoundation\Request;

/**
 * Dostep do panelu: jedno haslo z konfiguracji, bez kont uzytkownikow (spec 2).
 *
 * Panel jest wylaczony, dopoki ADMIN_PASSWORD nie zostanie ustawione. Domyslnie
 * otwarty panel na maszynie w sieci domowej to zaproszenie dla kazdego, kto
 * zgadnie adres - a haslo puste w repozytorium jest bezpiecznym stanem
 * poczatkowym tylko wtedy, gdy blokuje wejscie.
 */
final readonly class AdminAccess
{
    private const SESSION_KEY = 'admin_authenticated';

    public function __construct(
        private string $adminPassword,
    ) {
    }

    public function isEnabled(): bool
    {
        return '' !== trim($this->adminPassword);
    }

    /**
     * Sprawdza haslo i wpuszcza do panelu.
     *
     * hash_equals zamiast ===, zeby czas porownania nie zdradzal, ile znakow
     * sie zgadza. Regeneracja identyfikatora sesji zamyka droge do przejecia
     * sesji zalozonej przed logowaniem.
     */
    public function login(Request $request, string $password): bool
    {
        if (!$this->isEnabled() || !hash_equals($this->adminPassword, $password)) {
            return false;
        }

        $session = $request->getSession();
        $session->migrate(true);
        $session->set(self::SESSION_KEY, true);

        return true;
    }

    public function logout(Request $request): void
    {
        if ($request->hasSession()) {
            $request->getSession()->remove(self::SESSION_KEY);
            $request->getSession()->migrate(true);
        }
    }

    public function isLoggedIn(Request $request): bool
    {
        return $this->isEnabled()
            && $request->hasSession()
            && true === $request->getSession()->get(self::SESSION_KEY);
    }

    /**
     * Sprawdza dostep do panelu.
     *
     * Zwraca false, gdy trzeba sie zalogowac - kontroler przekierowuje wtedy
     * na formularz. Wyjatek 403 pokazywalby stronę bledu komus, kto po prostu
     * wszedl na /admin z zakladki.
     */
    public function needsLogin(Request $request): bool
    {
        return !$this->isLoggedIn($request);
    }

    /**
     * Bezpieczna sciezka powrotu po zalogowaniu - z pola formularza (POST)
     * albo z parametru w adresie (GET, doklejony przy przekierowaniu na
     * logowanie). Bez tego kazde logowanie ladowalo na sztywno w liscie
     * kategorii, nawet gdy ktos chcial dostac sie np. do listy zapisanych
     * turniejow - trzeba bylo klikac dalej recznie.
     *
     * Musi zaczynac sie od pojedynczego "/" i nie moze byc adresem zewnetrznym
     * (protocol-relative "//" albo pelny "https://") - inaczej link do
     * logowania z podstawionym ?next=https://obcy.pl przekierowywalby
     * zalogowanego admina gdziekolwiek (open redirect).
     */
    public function safeNextPath(Request $request): ?string
    {
        $next = $request->request->get('next') ?? $request->query->get('next');

        if (!\is_string($next) || '' === $next) {
            return null;
        }

        if (!str_starts_with($next, '/') || str_starts_with($next, '//') || str_contains($next, '://')) {
            return null;
        }

        return $next;
    }
}
