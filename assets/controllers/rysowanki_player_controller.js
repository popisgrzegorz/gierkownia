import { Controller } from '@hotwired/stimulus';

/**
 * Sterownik telefonu w kalamburach rysowanych.
 *
 * Nie odpytuje serwera o widok: trasa game_view jest dla hosta (patrz
 * kalambury_player_controller.js - ten sam powód), a telefon dostaje stan
 * początkowy w szablonie i dalej żyje wyłącznie z eventów SSE.
 *
 * Telefon nie ma żadnej tajemnicy do pokazania - hasło idzie na tablet
 * (rysowanki_host_controller.js, rola "tablet"), więc telefon tylko melduje,
 * co się teraz dzieje: czyja kolej rysować i kiedy zgadywać na głos.
 */
export default class extends Controller {
    static targets = ['note', 'clock', 'clockValue'];
    static values = { state: String, myTeamDrawing: Boolean };

    connect() {
        this.offset = 0;
        this.teamId = this.element.dataset.teamId;

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
            case 'category_picked':
            case 'choices_ready':
            case 'drawing_started':
                this.myTeamDrawingValue = event.turn === this.teamId;
                break;

            case 'turn':
                this.myTeamDrawingValue = event.teamId === this.teamId;
                break;

            case 'timer':
                this.#startClock(event.deadline, event.serverNow);
                break;

            case 'round_over':
                clearInterval(this.ticker);
                this.clockTarget.hidden = true;
                break;

            case 'phase':
                this.stateValue = event.state;
                break;

            case 'reset':
                this.myTeamDrawingValue = false;
                break;
        }

        this.#render();
    }

    #render() {
        this.noteTarget.textContent = {
            idle: 'Host przygotowuje kategorię…',
            dealt: 'Rysujący wybiera hasło na tablecie…',
            drawing: this.myTeamDrawingValue
                ? 'Wasza drużyna rysuje! Podajcie tablet osobie rysującej.'
                : 'Zgaduj na głos! 🎨',
            guessed: 'Runda zakończona.',
            timeout: 'Czas minął.',
        }[this.stateValue] ?? '';
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
