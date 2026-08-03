/**
 * Service worker tylko instalacyjny - zero cache'owania.
 *
 * Kamien 4 (PWA) mial otwarte pytanie: czy gra ma dalej dzialac bez internetu
 * w LAN (patrz CLAUDE.md, "Decyzje czekajace"). Cache'owanie samego Tablera
 * (z CDN) albo assetow gry przesadzaloby to pytanie bez rozmowy z uzytkownikiem -
 * ten plik daje instalowalnosc (kryterium przegladarek: zarejestrowany worker
 * z obsluga 'fetch') bez dotykania offline'u. Kazde zadanie leci normalnie
 * do sieci, jakby workera nie bylo.
 */
self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', (event) => {
    event.respondWith(fetch(event.request));
});
