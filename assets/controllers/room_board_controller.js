import { Controller } from '@hotwired/stimulus';
import { MercureStream } from '../live/mercure_stream.js';

/**
 * Tablo na ekranie hosta. Sluchan eventow pokoju i aktualizuje liste druzyn
 * oraz punkty - telewizor nigdy sie nie odswieza recznie.
 */
export default class extends Controller {
    static targets = ['list', 'empty', 'count', 'template', 'connection', 'joinPanel'];
    static values = { hub: String, code: String, scoreUrl: String };

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

    /**
     * Wysuwa panel z kodem QR. W trakcie gry jest schowany, bo zabierał ekran
     * planszy przez cały czas, a potrzebny jest tylko w chwili dołączania -
     * najczęściej komuś, kto dosiadł się w połowie imprezy.
     */
    toggleJoin() {
        if (this.hasJoinPanelTarget) {
            this.joinPanelTarget.hidden = !this.joinPanelTarget.hidden;
        }
    }

    /**
     * Przyznaje lub odejmuje punkt. Nowy wynik nie jest wpisywany tutaj -
     * wraca eventem 'score' z Mercure, tak samo jak do telefonów. Dzięki temu
     * ekran hosta i telefony pokazują dokładnie to samo, nawet gdy dwa punkty
     * padną w tej samej sekundzie.
     */
    async score(event) {
        const button = event.currentTarget;
        const teamId = button.closest('[data-team-id]')?.dataset.teamId;

        if (!teamId) {
            return;
        }

        // Blokada na czas żądania: host klika szybciej, niż leci odpowiedź,
        // a podwójne kliknięcie to podwójny punkt.
        button.disabled = true;

        try {
            const response = await fetch(this.scoreUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ teamId, delta: Number(button.dataset.delta) }),
            });

            if (!response.ok) {
                this.#showConnection('offline');
            }
        } catch {
            this.#showConnection('offline');
        } finally {
            button.disabled = false;
        }
    }

    #handle(event) {
        // Jeden strumień SSE na ekran: panel gry (melodia, koło) słucha tego
        // zdarzenia zamiast otwierać hubowi drugie połączenie.
        window.dispatchEvent(new CustomEvent('gierki:event', { detail: event }));

        switch (event.type) {
            case 'team_joined':
                this.#addTeam(event.team, event.score ?? 0);
                break;
            case 'score':
                this.#setScore(event.teamId, event.total);
                break;
            case 'reset':
                this.#resetScores();
                break;
        }
    }

    #addTeam(team, score) {
        // Event moze przyjsc drugi raz (reconnect SSE odtwarza historie),
        // wiec dokladamy kafelek tylko gdy tej druzyny jeszcze nie ma.
        if (!team?.id || this.#tile(team.id)) {
            return;
        }

        const tile = this.templateTarget.content.firstElementChild.cloneNode(true);
        tile.dataset.teamId = team.id;
        tile.style.setProperty('--team-color', team.color);
        tile.querySelector('.team-name').textContent = team.name;
        tile.querySelector('[data-score]').textContent = String(score);

        this.listTarget.append(tile);
        this.#refreshCount();
        this.#reorder();
    }

    #setScore(teamId, total) {
        const scoreEl = this.#tile(teamId)?.querySelector('[data-score]');
        if (!scoreEl) {
            return;
        }

        scoreEl.textContent = String(total);
        scoreEl.classList.remove('bump');
        void scoreEl.offsetWidth; // restart animacji przy kolejnym punkcie z rzedu
        scoreEl.classList.add('bump');

        this.#reorder();
    }

    /**
     * Ustawia drużyny wg punktów i przesuwa kafelki animacją.
     *
     * Technika FLIP: mierzymy pozycje PRZED przestawieniem, przestawiamy
     * w DOM-ie, mierzymy po - i odtwarzamy różnicę jako ruch. Animowanie
     * samego "top" zmuszałoby przeglądarkę do przeliczania układu w każdej
     * klatce; transform idzie po stronie kompozytora i nie gubi klatek nawet
     * na słabym procesorze telewizora.
     */
    #reorder() {
        const tiles = [...this.listTarget.children];

        if (tiles.length < 2) {
            this.#renumber();

            return;
        }

        const before = new Map(tiles.map((tile) => [tile, tile.getBoundingClientRect().top]));

        // Malejąco po punktach; przy remisie zostaje dotychczasowa kolejność,
        // żeby kafelki nie skakały bez powodu przy każdym punkcie.
        const sorted = [...tiles].sort((a, b) => this.#scoreOf(b) - this.#scoreOf(a));
        sorted.forEach((tile) => this.listTarget.append(tile));

        this.#renumber();

        sorted.forEach((tile) => {
            const delta = before.get(tile) - tile.getBoundingClientRect().top;

            if (0 === delta) {
                return;
            }

            tile.animate(
                [{ transform: `translateY(${delta}px)` }, { transform: 'none' }],
                { duration: 420, easing: 'cubic-bezier(0.2, 0.7, 0.3, 1)' },
            );
        });
    }

    #renumber() {
        [...this.listTarget.children].forEach((tile, index) => {
            const rank = tile.querySelector('.team-rank');

            if (rank) {
                rank.textContent = String(index + 1);
            }
        });
    }

    #scoreOf(tile) {
        return Number(tile.querySelector('[data-score]')?.textContent ?? 0);
    }

    #resetScores() {
        this.listTarget.querySelectorAll('[data-score]').forEach((el) => {
            el.textContent = '0';
        });

        this.#renumber();
    }

    #tile(teamId) {
        return this.listTarget.querySelector(`[data-team-id="${CSS.escape(teamId)}"]`);
    }

    #refreshCount() {
        const total = this.listTarget.children.length;

        if (this.hasCountTarget) {
            this.countTarget.textContent = `(${total})`;
        }
        if (this.hasEmptyTarget) {
            this.emptyTarget.hidden = total > 0;
        }
    }

    #showConnection(state) {
        if (!this.hasConnectionTarget) {
            return;
        }

        this.connectionTarget.dataset.state = state;
        this.connectionTarget.textContent = {
            connecting: 'łączenie…',
            online: 'na żywo',
            offline: 'brak połączenia',
        }[state] ?? state;
    }
}
