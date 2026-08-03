/**
 * Rejestruje /sw.js (poza AssetMapperem - stały adres, zasięg całej domeny).
 * Wymaga bezpiecznego kontekstu (HTTPS albo localhost); w LAN pod adresem IP
 * bez TLS `navigator.serviceWorker` po prostu nie istnieje, więc funkcja
 * cicho nic nie robi zamiast wywalać błąd w konsoli.
 */
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            // Rejestracja może nie wyjść (np. brak HTTPS w LAN) - gra działa
            // dalej bez PWA, to nie jest błąd krytyczny.
        });
    });
}
