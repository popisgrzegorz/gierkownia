import { Controller } from '@hotwired/stimulus';
import { sfx } from '../audio/sfx.js';

/* Od ilu milisekund przed końcem rundy zaczyna się odliczanie. */
const TICK_FROM = 5000;

/**
 * Ile czasu po evencie `timer` faza, która go wystawiła, ma na dotarcie.
 *
 * Kolejność jest znana: silnik wysyła najpierw wiadomości modułu (w tym
 * `timer`), a dopiero po nich zmianę fazy - oba z tego samego żądania, więc
 * dzielą je milisekundy. Późniejsza zmiana fazy znaczy już co innego: runda
 * skończyła się przed czasem (wszyscy odpowiedzieli, ktoś zgadł hasło)
 * i odliczanie ma zamilknąć, zamiast dotykać końca nad zamkniętą rundą.
 */
const OWN_PHASE_MS = 400;

/**
 * Dźwięk pokoju na ekranie hosta.
 *
 * Tu siedzą wyłącznie zdarzenia rdzenia - dołączenie drużyny, punkt, buzzer,
 * odliczanie. Dźwięki własne gier należą do ich modułów (tak samo jak kolor
 * kafelka i ilustracja), więc nowa gra nie dopisuje się do żadnej listy w tym
 * pliku - importuje `sfx` i gra swoje.
 */
export default class extends Controller {
    static targets = ['button'];

    connect() {
        this.onEvent = (event) => this.#handle(event.detail);
        window.addEventListener('gierki:event', this.onEvent);

        // Przeglądarka nie wyda dźwięku, dopóki ktoś czegoś nie kliknie.
        // Host i tak klika - wybiera grę, losuje hasło - więc wystarczy złapać
        // pierwszy gest z brzegu i obudzić nim kontekst.
        this.onGesture = () => sfx.unlock();
        window.addEventListener('pointerdown', this.onGesture);
        window.addEventListener('keydown', this.onGesture);

        this.#showState();
    }

    disconnect() {
        window.removeEventListener('gierki:event', this.onEvent);
        window.removeEventListener('pointerdown', this.onGesture);
        window.removeEventListener('keydown', this.onGesture);
        this.#stopCountdown();
    }

    toggle() {
        sfx.toggle();
        this.#showState();
    }

    #handle(event) {
        switch (event.type) {
            case 'team_joined':
                sfx.play('join');
                break;

            case 'score':
                sfx.play('point');
                break;

            case 'armed':
                sfx.play('armed');
                break;

            case 'buzz':
                sfx.play('buzz');
                break;

            case 'timer':
                this.#countdown(event.deadline, event.serverNow);
                break;

            case 'phase':
                if (Date.now() - (this.timerAt ?? 0) > OWN_PHASE_MS) {
                    this.#stopCountdown();
                }
                break;

            case 'reset':
                this.#stopCountdown();
                break;
        }
    }

    /**
     * Odliczanie ostatnich sekund.
     *
     * Liczymy z pary {deadline, serverNow} - tak samo jak zegary na ekranach.
     * Nie ustawiamy pięciu budzików z góry: telewizor bywa zostawiony na kilka
     * godzin, a przeglądarki przycinają timery w tle. Każde tyknięcie wyznacza
     * termin następnego z prawdziwego czasu, więc rozjazd się nie kumuluje.
     */
    #countdown(deadline, serverNow) {
        this.#stopCountdown();

        if (!deadline) {
            return;
        }

        this.timerAt = Date.now();
        const offset = serverNow - Date.now();

        const fire = () => {
            const left = deadline - (Date.now() + offset);

            if (left <= 120) {
                sfx.play('timeUp');

                return;
            }

            if (left <= TICK_FROM) {
                sfx.play(left <= 1500 ? 'tickLast' : 'tick');
            }

            const next = left > TICK_FROM ? left - TICK_FROM : (left % 1000) || 1000;
            this.ticker = setTimeout(fire, Math.max(30, next));
        };

        fire();
    }

    #stopCountdown() {
        clearTimeout(this.ticker);
        this.ticker = null;
    }

    #showState() {
        if (this.hasButtonTarget) {
            this.buttonTarget.textContent = sfx.muted ? '🔇 Dźwięk' : '🔊 Dźwięk';
            this.buttonTarget.setAttribute('aria-pressed', sfx.muted ? 'false' : 'true');
        }
    }
}
