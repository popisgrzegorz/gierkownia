import { Controller } from '@hotwired/stimulus';

const LETTERS = ['A', 'B', 'C', 'D'];

/**
 * Test melodii na telefonie.
 *
 * Nie odpytuje serwera o widok: trasa game_view jest dla hosta, a telefon
 * dostaje stan początkowy w szablonie i dalej żyje z eventów - tak samo jak
 * pozostałe sterowniki.
 *
 * Liczy się pierwsza odpowiedź, więc przyciski blokują się od razu po kliknięciu.
 * Serwer i tak odrzuci drugą, ale gracz ma widzieć swoją decyzję natychmiast,
 * a nie po powrocie żądania.
 */
export default class extends Controller {
    static targets = ['clock', 'clockValue', 'choices', 'note'];
    static values = { actionUrl: String, state: String, mine: String };

    connect() {
        this.offset = 0;

        this.onEvent = (event) => this.#handle(event.detail);
        window.addEventListener('gierki:event', this.onEvent);

        this.#render();
    }

    disconnect() {
        window.removeEventListener('gierki:event', this.onEvent);
        clearInterval(this.ticker);
    }

    async choose(event) {
        if ('answering' !== this.stateValue || '' !== this.mineValue) {
            return;
        }

        const option = Number(event.currentTarget.dataset.option);

        this.mineValue = String(option);
        this.#render();

        const ok = await this.#send('answer', { option });

        if (!ok) {
            // Bez tego telefon pokazywal odpowiedz jako "wybrana" (i blokowal
            // zmiane wyboru), mimo ze serwer jej nigdy nie dostal - gracz
            // przegrywal runde, nie wiedzac dlaczego.
            this.mineValue = '';
            this.#render();
        }
    }

    #handle(event) {
        switch (event.type) {
            case 'question_ready':
                this.mineValue = '';
                this.correct = null;
                this.#rebuild(event.options);
                this.stateValue = 'ready';
                break;

            case 'timer':
                this.#startClock(event.deadline, event.serverNow);
                break;

            case 'again':
                // Ten sam utwór jeszcze raz - odpowiedzi wracają do wyboru.
                this.mineValue = '';
                this.correct = null;
                this.stateValue = 'answering';
                break;

            case 'results':
                this.correct = event.correct;
                this.stateValue = 'results';
                clearInterval(this.ticker);
                this.clockTarget.hidden = true;
                break;

            case 'round_reset':
                this.mineValue = '';
                this.correct = null;
                this.stateValue = 'idle';
                break;

            case 'phase':
                this.stateValue = event.state;
                break;

            case 'reset':
                this.mineValue = '';
                this.correct = null;
                this.stateValue = 'idle';
                break;
        }

        this.#render();
    }

    #render() {
        const answering = 'answering' === this.stateValue;
        const results = 'results' === this.stateValue;
        const mine = '' === this.mineValue ? null : Number(this.mineValue);

        this.#buttons().forEach((button) => {
            const option = Number(button.dataset.option);

            button.classList.toggle('picked', option === mine);
            button.classList.remove('good', 'bad', 'dim');

            if (results) {
                if (option === this.correct) {
                    button.classList.add('good');
                } else if (option === mine) {
                    button.classList.add('bad');
                } else {
                    button.classList.add('dim');
                }
            }

            button.disabled = !answering || null !== mine;
        });

        this.noteTarget.textContent = this.#note(mine, results, answering);
    }

    #note(mine, results, answering) {
        if (results) {
            if (null === mine) {
                return 'Runda skończona — nie zdążyliście odpowiedzieć.';
            }

            return mine === this.correct ? 'Dobrze!' : 'Niestety — poprawna jest podświetlona.';
        }

        if (answering) {
            return null === mine ? 'Wybierz jeden z czterech tytułów.' : 'Odpowiedź wysłana. Czekamy na resztę.';
        }

        return 'Czekaj na start rundy…';
    }

    /** Nowe pytanie: te same cztery pozycje, ale nowe tytuły. */
    #rebuild(options) {
        if (!Array.isArray(options) || 0 === options.length) {
            return;
        }

        this.choicesTarget.replaceChildren(...options.map((title, index) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'quiz-choice';
            button.dataset.option = String(index);
            button.dataset.action = 'quiz-player#choose';

            const letter = document.createElement('span');
            letter.className = 'quiz-letter';
            letter.textContent = LETTERS[index] ?? '?';

            const text = document.createElement('span');
            text.className = 'quiz-title';
            text.textContent = title;

            button.append(letter, text);

            return button;
        }));
    }

    #buttons() {
        return [...this.choicesTarget.querySelectorAll('button[data-option]')];
    }

    #startClock(deadline, serverNow) {
        clearInterval(this.ticker);

        this.stateValue = 'answering';
        this.offset = serverNow - Date.now();
        this.clockTarget.hidden = false;

        const tick = () => {
            const left = Math.max(0, deadline - (Date.now() + this.offset));
            this.clockValueTarget.textContent = Math.ceil(left / 1000);
            this.clockTarget.dataset.state = left < 10_000 ? 'urgent' : 'running';

            if (0 === left) {
                clearInterval(this.ticker);
                // Blokada przycisków to uprzejmość wobec gracza - o tym,
                // co wejdzie, i tak rozstrzyga serwer.
                this.stateValue = 'results';
                this.#render();
            }
        };

        tick();
        this.ticker = setInterval(tick, 200);
        this.#render();
    }

    /**
     * @return {Promise<boolean>} czy serwer przyjął żądanie
     */
    async #send(action, payload = {}) {
        let response;

        try {
            response = await fetch(this.actionUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ action, payload }),
            });
        } catch {
            this.noteTarget.textContent = 'Brak połączenia — spróbuj ponownie.';

            return false;
        }

        if (!response.ok) {
            // Bez tego wybrana odpowiedź znikała bez śladu - gracz widział u
            // siebie zaznaczony kafelek, a serwer nigdy go nie dostał.
            const problem = await response.json().catch(() => ({}));
            this.noteTarget.textContent = problem.detail ?? 'Nie udało się wysłać - spróbuj ponownie.';

            return false;
        }

        return true;
    }
}
