import { Controller } from '@hotwired/stimulus';

/**
 * Wybór gry na ekranie hosta. Lista modułów pochodzi z serwera - rdzeń nie zna
 * żadnej konkretnej gry, więc i ten kontroler nie wymienia ich z nazwy.
 */
export default class extends Controller {
    static values = { startUrl: String };

    async start(event) {
        const button = event.currentTarget;
        button.disabled = true;

        const response = await fetch(this.startUrlValue, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ gameType: button.dataset.gameType }),
        });

        if (!response.ok) {
            const problem = await response.json().catch(() => ({}));
            button.disabled = false;
            button.textContent = problem.detail ?? 'Nie udało się';

            return;
        }

        // Panel gry to osobny szablon serwerowy, więc po starcie przeładowujemy
        // ekran zamiast składać go w JS. Dzieje się to raz na grę.
        window.location.reload();
    }
}
