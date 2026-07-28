/**
 * Nawigacja bez pełnego przeładowania strony.
 *
 * Pobiera nowy ekran i podmienia zawartość <body>, ale NIE rusza <html>.
 * To kluczowe dla pełnego ekranu na telewizorze: przeglądarka trzyma stan
 * fullscreen na elemencie <html> (documentElement) i porzuca go przy każdym
 * pełnym przeładowaniu, a włączyć z powrotem nie pozwala bez gestu użytkownika.
 * Podmieniając tylko wnętrze <body>, zostawiamy <html> w spokoju - host, który
 * raz włączył pełny ekran, zostaje w nim przez start gry, zmianę gry i reset.
 *
 * Stimulus obserwuje całą stronę, więc po podmianie sam odłącza stare kontrolery
 * i podłącza nowe - panel gry startuje dokładnie tak, jak po twardym reload.
 *
 * @param {string} url
 * @param {RequestInit} options
 */
export async function softNavigate(url = window.location.href, options = {}) {
    try {
        const response = await fetch(url, {
            ...options,
            headers: { 'X-Soft-Nav': '1', ...(options.headers ?? {}) },
        });

        const html = await response.text();
        const next = new DOMParser().parseFromString(html, 'text/html');

        document.title = next.title || document.title;
        document.body.className = next.body.className;
        document.body.innerHTML = next.body.innerHTML;

        // Po redirectcie (np. „Zmień grę" wraca na ekran hosta) URL w pasku ma
        // się zgadzać z tym, co widać - inaczej odświeżenie strony poszłoby pod
        // adres akcji POST.
        if (response.url && response.url !== window.location.href) {
            window.history.replaceState({}, '', response.url);
        }
    } catch {
        // Sieć padła w połowie - lepiej twardo przejść niż zostać na martwym
        // ekranie. Pełny ekran się zgubi, ale to sytuacja awaryjna.
        window.location.assign(url);
    }
}
