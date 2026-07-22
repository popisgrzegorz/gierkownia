import { Controller } from '@hotwired/stimulus';

/**
 * Sterownik kalambur na telefonie.
 *
 * Nie odpytuje serwera o widok: trasa game_view jest dla hosta, a telefon
 * dostaje stan początkowy w szablonie i dalej żyje z eventów. Tak samo działa
 * sterownik koła - inaczej każde żądanie wracałoby odmową i ekran zostawałby
 * pusty, co widać było dopiero na zrzucie z telefonu.
 *
 * Hasło widzi wyłącznie aktor i przychodzi ono pod-topikiem jego drużyny,
 * nigdy rozgłoszeniem.
 */
export default class extends Controller {
    static targets = ['wordCard', 'word', 'clock', 'clockValue', 'note'];
    static values = { state: String, actor: Boolean };

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

    #handle(event) {
        switch (event.type) {
            case 'your_word':
                // Trafia wyłącznie na pod-topic drużyny aktora.
                this.wordTarget.textContent = event.word;
                this.actorValue = true;
                break;

            case 'timer':
                this.#startClock(event.deadline, event.serverNow);
                break;

            case 'card_drawn':
                // Kolejna karta: aktorem jest ta drużyna, którą wskazał serwer.
                this.actorValue = event.turn === this.element.dataset.teamId;
                break;

            case 'turn':
                this.actorValue = event.teamId === this.element.dataset.teamId;
                this.wordTarget.textContent = '';
                break;

            case 'round_over':
                clearInterval(this.ticker);
                this.clockTarget.hidden = true;
                this.wordTarget.textContent = '';
                break;

            case 'phase':
                this.stateValue = event.state;
                break;

            case 'reset':
                this.actorValue = false;
                this.wordTarget.textContent = '';
                break;
        }

        this.#render();
    }

    #render() {
        const hasWord = '' !== this.wordTarget.textContent.trim();

        this.wordCardTarget.hidden = !this.actorValue || !hasWord
            || !['drawn', 'acting'].includes(this.stateValue);

        this.noteTarget.textContent = this.actorValue
            ? { drawn: 'Za chwilę start — przygotuj się.', acting: 'Pokazuj!' }[this.stateValue]
                ?? 'Twoja kolej pokazywać.'
            : { acting: 'Zgaduj na głos!', drawn: 'Zaraz zacznie się pokaz.' }[this.stateValue]
                ?? 'Czekaj na swoją kolej.';
    }

    #startClock(deadline, serverNow) {
        clearInterval(this.ticker);

        // Offset względem zegara serwera - własnemu nie ma powodu ufać.
        this.offset = serverNow - Date.now();
        this.clockTarget.hidden = false;

        const tick = () => {
            const left = Math.max(0, deadline - (Date.now() + this.offset));
            this.clockValueTarget.textContent = Math.ceil(left / 1000);
            this.clockTarget.dataset.state = left < 10_000 ? 'urgent' : 'running';

            if (0 === left) {
                clearInterval(this.ticker);
            }
        };

        tick();
        this.ticker = setInterval(tick, 250);
    }
}
