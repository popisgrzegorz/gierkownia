import { Controller } from '@hotwired/stimulus';
import { softNavigate } from '../soft_nav.js';

/**
 * Wybór gry na ekranie hosta. Lista modułów pochodzi z serwera - rdzeń nie zna
 * żadnej konkretnej gry, więc i ten kontroler nie wymienia ich z nazwy.
 */
export default class extends Controller {
    static values = { startUrl: String };

    // „Losuj grę” - wybiera spośród kafelków na ekranie, więc tak jak reszta
    // kontrolera nie zna żadnej gry z nazwy.
    startRandom(event) {
        const tiles = [...this.element.querySelectorAll('[data-game-type]')];

        if (0 === tiles.length) {
            return;
        }

        event.currentTarget.disabled = true;
        const tile = tiles[Math.floor(Math.random() * tiles.length)];
        tile.focus();

        return this.#launch(tile);
    }

    async start(event) {
        return this.#launch(event.currentTarget);
    }

    async #launch(button) {
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

        // Panel gry to osobny szablon serwerowy - pobieramy go i podmieniamy
        // <body> BEZ pełnego przeładowania (soft_nav.js). Twardy reload
        // wyrzucałby hosta z pełnego ekranu przy każdym starcie gry.
        await softNavigate();
    }
}
