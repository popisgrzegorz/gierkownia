import { Controller } from '@hotwired/stimulus';

/**
 * Buzzer na telefonie drużyny.
 *
 * Zasada ze spec 9: naciśnięcie blokuje przycisk **lokalnie i natychmiast**,
 * jeszcze przed odpowiedzią serwera. Gracz w emocjach wali w ekran kilka razy,
 * a bez tej blokady poleciałoby kilka żądań pod rząd. O zwycięzcy i tak
 * rozstrzyga serwer — tutaj chodzi wyłącznie o to, żeby nie wysyłać śmieci
 * i żeby ekran natychmiast potwierdził dotknięcie.
 */
export default class extends Controller {
    static targets = ['button', 'label'];
    static values = { actionUrl: String, teamId: String, state: String, round: Number };

    connect() {
        this.onEvent = (event) => this.#handle(event.detail);
        window.addEventListener('gierki:event', this.onEvent);

        this.#render(this.stateValue);
    }

    disconnect() {
        window.removeEventListener('gierki:event', this.onEvent);
    }

    async buzz() {
        if (this.buttonTarget.disabled) {
            return;
        }

        this.#render('sent');

        try {
            await fetch(this.actionUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ action: 'buzz', payload: { round: this.roundValue } }),
            });
        } catch {
            // Żądanie nie doszło - odblokowujemy, żeby gracz mógł spróbować
            // ponownie. Jeśli mimo to dotarło, serwer i tak odrzuci duplikat.
            this.#render('armed');
        }
    }

    #handle(event) {
        switch (event.type) {
            case 'armed':
                this.roundValue = event.round;
                this.#render('armed');
                break;

            case 'buzz':
                this.#render(event.teamId === this.teamIdValue ? 'floor' : 'locked', event.teamName);
                break;

            case 'buzz_rejected':
                this.#render('locked');
                break;

            case 'judged':
            case 'reset':
                this.#render('waiting');
                break;

            case 'phase':
                if ('idle' === event.state || 'judging' === event.state) {
                    this.#render('waiting');
                }
                break;
        }
    }

    #render(state, winnerName = null) {
        const labels = {
            waiting: ['Czekaj…', true],
            armed: ['NACIŚNIJ!', false],
            sent: ['…', true],
            floor: ['MASZ GŁOS', true],
            locked: [winnerName ? `${winnerName} był szybszy` : 'Za późno', true],
        };

        const [text, disabled] = labels[state] ?? labels.waiting;

        this.element.dataset.state = state;
        this.buttonTarget.disabled = disabled;
        this.labelTarget.textContent = text;

        // Krótka wibracja jako potwierdzenie dotknięcia: telefon bywa trzymany
        // bokiem, w hałasie i bez patrzenia na ekran.
        if ('floor' === state && 'vibrate' in navigator) {
            navigator.vibrate(120);
        }
    }
}
