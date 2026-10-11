import { Controller } from '@hotwired/stimulus';

/**
 * Sterownik Ostatniej litery na telefonie.
 *
 * Nie odpytuje serwera o widok: trasa game_view jest dla hosta, a telefon
 * dostaje stan początkowy w szablonie i dalej żyje wyłącznie z eventów -
 * tak samo jak kalambury i państwa-miasta.
 */
export default class extends Controller {
    static targets = ['letter', 'clock', 'clockValue', 'inputRow', 'input', 'note', 'error'];
    static values = { actionUrl: String, state: String, myTurn: Boolean };

    /** Miekkie spolgloski koncowe i ich dwuznak - sama podpowiedz, walidacja jest po stronie serwera. */
    static DIGRAPHS = { ń: 'NI', ć: 'CI', ś: 'SI', ź: 'ZI' };

    connect() {
        this.offset = 0;
        this.lastWord = null;
        this.wasTypable = false;

        this.onEvent = (event) => this.#handle(event.detail);
        window.addEventListener('gierki:event', this.onEvent);

        this.#render();
    }

    disconnect() {
        window.removeEventListener('gierki:event', this.onEvent);
        clearInterval(this.ticker);
    }

    /** Gracz pisze wersalikami - dopasowane do wyswietlania slow na ekranie hosta. */
    normalizeInput() {
        const { selectionStart, selectionEnd } = this.inputTarget;
        this.inputTarget.value = this.inputTarget.value.toUpperCase();
        this.inputTarget.setSelectionRange(selectionStart, selectionEnd);
    }

    async submit() {
        const word = this.inputTarget.value.trim();

        if ('' === word) {
            return;
        }

        this.errorTarget.textContent = '';
        const ok = await this.#send('submit', { word });

        if (ok) {
            this.inputTarget.value = '';
        }
    }

    #handle(event) {
        const myTeamId = this.element.dataset.teamId;

        switch (event.type) {
            case 'game_started':
                this.stateValue = 'playing';
                this.myTurnValue = event.turn === myTeamId;
                this.lastWord = null;
                this.errorTarget.textContent = '';
                break;

            case 'timer':
                this.#startClock(event.deadline, event.serverNow);
                break;

            case 'word_accepted':
                this.lastWord = event.word;
                this.myTurnValue = event.turn === myTeamId;
                this.errorTarget.textContent = '';
                break;

            case 'word_pending':
                this.stateValue = 'judging';
                break;

            case 'word_ruled':
                this.stateValue = 'playing';

                if (event.accepted) {
                    this.lastWord = event.word;
                    this.myTurnValue = event.turn === myTeamId;
                } else if (this.myTurnValue) {
                    this.errorTarget.textContent = 'Sędzia uznał, że to nie jest słowo - spróbuj czegoś innego.';
                }
                break;

            case 'turn_missed':
                this.myTurnValue = event.turn === myTeamId;
                break;

            case 'team_eliminated':
                this.myTurnValue = event.turn === myTeamId;
                break;

            case 'game_over':
                clearInterval(this.ticker);
                this.clockTarget.hidden = true;
                this.myTurnValue = false;
                break;

            case 'phase':
                this.stateValue = event.state;
                break;

            case 'reset':
                this.stateValue = 'idle';
                this.myTurnValue = false;
                this.lastWord = null;
                break;
        }

        this.#render();
    }

    #render() {
        this.letterTarget.textContent = this.#requiredLetterDisplay();

        const canType = this.myTurnValue && 'playing' === this.stateValue;
        this.inputRowTarget.hidden = !canType;
        this.inputTarget.disabled = !canType;

        // Klawiatura telefonu zakrywa panel z wymagana litera - wpisujemy ja
        // wprost do pustego pola, zeby gracz mial ja przed oczami tam, gdzie
        // i tak juz patrzy. Tylko przy PRZEJSCIU na "moja tura", nie przy kazdym
        // renderze - inaczej kasowalibysmy to, co gracz wlasnie pisze.
        if (canType && !this.wasTypable) {
            this.#prefillRequiredLetter();
        }
        this.wasTypable = canType;

        if (canType) {
            this.inputTarget.focus();
        }

        this.noteTarget.textContent = {
            idle: 'Zaraz zaczynamy.',
            playing: this.myTurnValue ? 'Twoja kolej — pisz!' : 'Czekaj na swoją kolej.',
            judging: this.myTurnValue ? 'Sędzia sprawdza twoje słowo…' : 'Sędzia sprawdza słowo przeciwnika…',
            finished: 'Koniec gry.',
        }[this.stateValue] ?? '';
    }

    /** Podpowiedź na ekranie - sama walidacja słowa jest po stronie serwera. */
    #requiredLetterDisplay() {
        if (!this.lastWord) {
            return '?';
        }

        const last = this.lastWord.slice(-1).toLowerCase();
        const pair = this.constructor.DIGRAPHS[last];

        return pair ? `${last.toUpperCase()} / ${pair}` : last.toUpperCase();
    }

    /**
     * Miekka spolgloska ma dwa poprawne poczatki (patrz OstatniaLiteraGame::checkWord) -
     * do inputu wstawiamy dwuznak (np. "ni"), bo w polskich slowach na starcie
     * to on wystepuje - sam "ń" jako pierwsza litera realnego slowa praktycznie
     * sie nie zdarza.
     */
    #prefillRequiredLetter() {
        if (!this.lastWord) {
            return;
        }

        const last = this.lastWord.slice(-1).toLowerCase();
        const pair = this.constructor.DIGRAPHS[last];
        const prefill = pair ?? last.toUpperCase();

        this.inputTarget.value = prefill;
        this.inputTarget.setSelectionRange(prefill.length, prefill.length);
    }

    #startClock(deadline, serverNow) {
        clearInterval(this.ticker);

        this.offset = serverNow - Date.now();
        this.clockTarget.hidden = false;

        const tick = () => {
            const left = Math.max(0, deadline - (Date.now() + this.offset));
            this.clockValueTarget.textContent = Math.ceil(left / 1000);
            this.clockTarget.dataset.state = left < 3_000 ? 'urgent' : 'running';

            if (0 === left) {
                clearInterval(this.ticker);
            }
        };

        // Interwal PRZED pierwszym tick() - ten sam wyscig o podwojne zdarzenie
        // co w pozostalych grach (patrz kalambury_host_controller.js).
        this.ticker = setInterval(tick, 200);
        tick();
    }

    /**
     * @return {Promise<boolean>} czy serwer przyjął słowo
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
            this.errorTarget.textContent = 'Brak połączenia — spróbuj ponownie.';

            return false;
        }

        if (!response.ok) {
            const problem = await response.json().catch(() => ({}));
            this.errorTarget.textContent = problem.detail ?? 'Nie udało się wysłać - spróbuj ponownie.';

            return false;
        }

        return true;
    }
}
