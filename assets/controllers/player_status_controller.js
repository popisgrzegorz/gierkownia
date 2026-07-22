import { Controller } from '@hotwired/stimulus';
import { MercureStream } from '../live/mercure_stream.js';

/**
 * Sterownik telefonu - w M1 pokazuje tylko punkty wlasnej druzyny i stan
 * polaczenia. Buzzer i wejscia gier doloza sie tutaj w kolejnych modulach.
 */
export default class extends Controller {
    static targets = ['score', 'connection', 'waiting'];
    static values = { hub: String, team: String, game: String };

    connect() {
        this.stream = new MercureStream(this.hubValue, {
            onEvent: (event) => this.#handle(event),
            onState: (state) => this.#showConnection(state),
        });
        this.stream.connect();
    }

    disconnect() {
        this.stream?.disconnect();
    }

    #handle(event) {
        // Broadcast niesie punkty wszystkich druzyn; telefon reaguje na swoje.
        if ('score' === event.type && event.teamId === this.teamValue) {
            this.#showScore(event.total);
        }

        if ('reset' === event.type) {
            this.#showScore(0);
        }

        // Zmiana gry: sterownik to szablon renderowany przez serwer, wiec
        // telefon musi sie przeladowac, zeby dostac wlasciwy panel. Bez tego
        // ekran wisi na "Czekaj na hosta" albo w poprzedniej grze, dopoki
        // ktos nie odswiezy go recznie.
        if ('phase' === event.type) {
            const incoming = event.gameType ?? ('lobby' === event.state ? '' : this.gameValue);

            if (incoming !== this.gameValue) {
                window.location.reload();
            }
        }

        // Jeden strumień SSE na stronę: kontrolery modułów gier (buzzer melodii,
        // klawiatura koła) słuchają tego zdarzenia zamiast otwierać własne
        // połączenie. Telefon i tak ma tylko jedno łącze, a hub liczy każdego
        // subskrybenta osobno.
        window.dispatchEvent(new CustomEvent('gierki:event', { detail: event }));
    }

    #showScore(total) {
        if (!this.hasScoreTarget) {
            return;
        }

        this.scoreTarget.textContent = String(total);
        this.scoreTarget.classList.remove('bump');
        void this.scoreTarget.offsetWidth;
        this.scoreTarget.classList.add('bump');
    }

    #showConnection(state) {
        if (!this.hasConnectionTarget) {
            return;
        }

        this.connectionTarget.dataset.state = state;
        this.connectionTarget.textContent = {
            connecting: 'łączenie…',
            online: 'połączono',
            offline: 'brak połączenia',
        }[state] ?? state;
    }
}
