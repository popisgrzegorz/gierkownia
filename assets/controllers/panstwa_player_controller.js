import { Controller } from '@hotwired/stimulus';

/**
 * Sterownik państw i miast na telefonie.
 *
 * Nie odpytuje serwera o widok: trasa game_view jest dla hosta, a telefon
 * dostaje stan początkowy w szablonie i dalej żyje z eventów - tak samo jak
 * kalambury i koło.
 *
 * Każda zmiana w polu leci na serwer z opóźnieniem, a nie dopiero na koniec:
 * runda kończy się twardo i odpowiedź trzymana w przeglądarce w chwili
 * upływu czasu byłaby odpowiedzią straconą.
 */
export default class extends Controller {
    static targets = ['letter', 'clock', 'clockValue', 'fields', 'stop', 'note'];
    static values = { actionUrl: String, state: String, letter: String, stopped: Boolean };

    /** Na tyle długo, żeby nie wysyłać co literę, i na tyle krótko, by nie zgubić słowa. */
    static DEBOUNCE = 400;

    connect() {
        this.offset = 0;
        this.timers = new Map();
        // Kolejka wysyłek per rubryka: zmiany tej samej rubryki lecą po kolei,
        // żeby krótszy (wcześniejszy) wpis nie nadpisał na serwerze dłuższego,
        // gdyby dwa żądania minęły się w drodze.
        this.sending = new Map();

        this.onEvent = (event) => this.#handle(event.detail);
        window.addEventListener('gierki:event', this.onEvent);

        this.#render();
    }

    disconnect() {
        window.removeEventListener('gierki:event', this.onEvent);
        clearInterval(this.ticker);
        this.timers.forEach((timer) => clearTimeout(timer));
    }

    /** Wpisywanie: wysyłamy po chwili ciszy, osobno dla każdej rubryki. */
    type(event) {
        const input = event.currentTarget;
        const column = input.dataset.column;

        clearTimeout(this.timers.get(column));

        // Po STOP-ie liczy się każda sekunda: debounce zjadłby ostatnią literę
        // wpisaną tuż przed twardym końcem rundy, więc wtedy wysyłamy od razu.
        // Dopóki nikt nie nacisnął STOP, czasu jest dość - grupujemy zmiany
        // debounce'em, żeby nie słać osobnego żądania na każdy znak.
        if ('ending' === this.stateValue) {
            this.timers.delete(column);
            this.#queueAnswer(column, input.value);
        } else {
            this.timers.set(column, setTimeout(() => {
                this.#queueAnswer(column, input.value);
            }, this.constructor.DEBOUNCE));
        }

        // Stan przycisku od razu, bez czekania na wysyłkę - inaczej STOP
        // odblokowywałby się pół sekundy po dopisaniu ostatniego słowa.
        this.#render();
    }

    async stop() {
        this.stopTarget.disabled = true;

        // Przed STOP-em dosyłamy wszystko, co czeka w kolejce - inaczej własne
        // ostatnie słowo przepadłoby przez własne kliknięcie.
        await this.#flushPending();
        await this.#send('stop');
    }

    #handle(event) {
        switch (event.type) {
            case 'letter_drawn':
                this.letterValue = event.letter;
                this.stateValue = 'writing';
                this.stoppedValue = false;
                this.#rebuildFields(event.columns);
                break;

            case 'timer':
                this.#startClock(event.deadline, event.serverNow);
                break;

            case 'stopped':
                this.stateValue = 'ending';
                // Kto nacisnął STOP, ten skończył - jego pola się zamykają.
                this.stoppedValue = event.teamId === this.element.dataset.teamId;
                break;

            case 'results':
                this.stateValue = 'scoring';
                clearInterval(this.ticker);
                break;

            case 'round_reset':
                this.stateValue = 'idle';
                this.letterValue = '';
                this.stoppedValue = false;
                this.#clearFields();
                break;

            case 'phase':
                this.stateValue = event.state;
                break;

            case 'reset':
                this.stateValue = 'idle';
                this.letterValue = '';
                this.#clearFields();
                break;
        }

        this.#render();
    }

    #render() {
        // Po własnym STOP-ie kartka jest zamknięta, choć runda jeszcze trwa.
        const writing = 'writing' === this.stateValue
            || ('ending' === this.stateValue && !this.stoppedValue);

        this.letterTarget.textContent = this.letterValue || '—';
        this.stopTarget.hidden = 'writing' !== this.stateValue;

        if ('writing' === this.stateValue) {
            // STOP dopiero z kompletną kartką - serwer i tak to sprawdzi,
            // ale wyszarzony przycisk mówi to bez komunikatu o błędzie.
            this.stopTarget.disabled = !this.#allFilled();
        }

        this.#inputs().forEach((input) => {
            input.disabled = !writing;
        });

        this.noteTarget.textContent = {
            idle: 'Czekaj na literę…',
            writing: this.#allFilled()
                ? 'Komplet! Możecie nacisnąć STOP.'
                : 'Wypełnijcie wszystkie rubryki — dopiero wtedy STOP.',
            ending: this.stoppedValue
                ? 'Nacisnęliście STOP — wasze odpowiedzi są zamknięte.'
                : 'Ktoś nacisnął STOP — kończ, zanim minie czas.',
            scoring: 'Runda skończona. Wyniki na telewizorze.',
        }[this.stateValue] ?? '';
    }

    /** Nowa runda: te same rubryki, ale puste - i znów aktywne. */
    #rebuildFields(columns) {
        if (!Array.isArray(columns) || 0 === columns.length) {
            this.#clearFields();

            return;
        }

        const existing = this.#inputs().map((input) => input.dataset.column);
        const same = existing.length === columns.length
            && existing.every((column, index) => column === columns[index]);

        if (same) {
            this.#clearFields();

            return;
        }

        this.fieldsTarget.replaceChildren(...columns.map((column) => {
            const label = document.createElement('label');
            label.className = 'pm-field';

            const name = document.createElement('span');
            name.className = 'pm-field-name';
            name.textContent = column;

            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'form-control pm-input';
            input.dataset.column = column;
            input.dataset.action = 'input->panstwa-player#type';
            input.autocomplete = 'off';
            input.spellcheck = false;
            input.maxLength = 60;

            label.append(name, input);

            return label;
        }));
    }

    #clearFields() {
        this.#inputs().forEach((input) => {
            input.value = '';
        });
    }

    /** Kartka jest kompletna, gdy żadne pole nie zostało puste. */
    #allFilled() {
        const inputs = this.#inputs();

        return 0 < inputs.length && inputs.every((input) => '' !== input.value.trim());
    }

    #inputs() {
        return [...this.fieldsTarget.querySelectorAll('input[data-column]')];
    }

    async #flushPending() {
        this.timers.forEach((timer) => clearTimeout(timer));
        this.timers.clear();

        await Promise.all(this.#inputs()
            .filter((input) => '' !== input.value.trim())
            .map((input) => this.#queueAnswer(input.dataset.column, input.value)));
    }

    /**
     * Wysyła jedną rubrykę, ale ustawia wysyłki tej samej rubryki w ogonek:
     * następna rusza dopiero, gdy poprzednia wróci. Bez tego dwa żądania z tego
     * samego pola mogłyby dotrzeć w odwrotnej kolejności i krótszy wpis
     * nadpisałby dłuższy - znów zniknęłaby ostatnia litera.
     */
    #queueAnswer(column, text) {
        const previous = this.sending.get(column) ?? Promise.resolve();
        const next = previous
            .catch(() => {})
            .then(() => this.#send('answer', { column, text }));

        this.sending.set(column, next);

        return next;
    }

    #startClock(deadline, serverNow) {
        clearInterval(this.ticker);

        // Zegar telefonu bywa przestawiony; liczymy od różnicy wobec serwera.
        this.offset = serverNow - Date.now();
        this.clockTarget.hidden = false;

        const tick = () => {
            const left = Math.max(0, deadline - (Date.now() + this.offset));
            this.clockValueTarget.textContent = Math.ceil(left / 1000);
            this.clockTarget.dataset.state = left < 10_000 ? 'urgent' : 'running';

            if (0 === left) {
                clearInterval(this.ticker);
                // Dogoń wszystko, co jeszcze wisi w kolejce, zanim zamkniemy
                // kartkę - ostatnie słowo nie może przepaść przez własny debounce.
                this.#flushPending();
                // Blokada pól jest tylko uprzejmością wobec gracza - o tym,
                // co wejdzie, i tak rozstrzyga serwer.
                this.stateValue = 'scoring';
                this.#render();
            }
        };

        tick();
        this.ticker = setInterval(tick, 200);
    }

    async #send(action, payload = {}) {
        await fetch(this.actionUrlValue, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ action, payload }),
        });
    }
}
