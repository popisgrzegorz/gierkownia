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
        /** Trwające przewijanie licznika - jedno na kafelek drużyny. */
        this.counters = new Map();

        this.stream = new MercureStream(this.hubValue, {
            onEvent: (event) => this.#handle(event),
            onState: (state) => this.#showConnection(state),
        });
        this.stream.connect();

        this.onKey = (event) => {
            if ('Escape' === event.key) {
                this.#focus(false);
            }
        };
        // Wyjście z pełnego ekranu przeglądarki (Esc, gest telewizora) ma
        // sprowadzić z powrotem pasek i punktację - inaczej ekran zostaje
        // okrojony, choć nikt o to nie prosił.
        this.onFullscreen = () => {
            if (!document.fullscreenElement) {
                this.#focus(false);
            }
        };

        window.addEventListener('keydown', this.onKey);
        document.addEventListener('fullscreenchange', this.onFullscreen);
    }

    disconnect() {
        this.stream?.disconnect();
        window.removeEventListener('keydown', this.onKey);
        document.removeEventListener('fullscreenchange', this.onFullscreen);
    }

    /**
     * Chowa pasek u góry i punktację z boku - zostaje sama scena gry.
     *
     * Punkty nadal się liczą; znika tylko ich podgląd i ręczna korekta, więc
     * wyjście musi być widoczne przez cały czas (przycisk w rogu).
     */
    toggleFocus() {
        this.#focus(!this.element.classList.contains('tv-focus'));
    }

    #focus(on) {
        if (on === this.element.classList.contains('tv-focus')) {
            return;
        }

        this.element.classList.toggle('tv-focus', on);

        // Przy okazji prawdziwy pełny ekran: na telewizorze pasek adresu zjada
        // tyle samo miejsca, co nasz nagłówek. Odmowa nic nie psuje - układ
        // i tak jest już schowany.
        if (on) {
            document.documentElement.requestFullscreen?.().catch(() => {});
        } else if (document.fullscreenElement) {
            document.exitFullscreen?.().catch(() => {});
        }
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

        this.#countTo(scoreEl, total);
        scoreEl.classList.remove('bump');
        void scoreEl.offsetWidth; // restart animacji przy kolejnym punkcie z rzedu
        scoreEl.classList.add('bump');

        this.#reorder();
    }

    /**
     * Przewija liczbę do nowego wyniku zamiast ją podmieniać.
     *
     * W kole fortuny runda potrafi dorzucić kilkaset punktów - skok z 300 na
     * 1750 nie mówi nic, a przewinięcie widać kątem oka z całej sali. Krok
     * dobieramy tak, by zawsze trwało tyle samo: przy jednym punkcie licznik
     * po prostu się przełącza.
     */
    #countTo(element, total) {
        const from = Number.parseInt(element.textContent, 10) || 0;

        cancelAnimationFrame(this.counters.get(element));

        if (from === total || 1 === Math.abs(total - from)) {
            element.textContent = String(total);

            return;
        }

        const started = performance.now();
        const DURATION = 520;

        const step = (now) => {
            // Wyhamowanie na końcu (ease-out) - liczba dobiega do wyniku
            // i staje, zamiast urywać się w pół biegu.
            const progress = Math.min(1, (now - started) / DURATION);
            const eased = 1 - (1 - progress) ** 3;

            element.textContent = String(Math.round(from + (total - from) * eased));

            if (1 > progress) {
                this.counters.set(element, requestAnimationFrame(step));
            }
        };

        this.counters.set(element, requestAnimationFrame(step));
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
