import { Controller } from '@hotwired/stimulus';
import { MercureStream } from '../live/mercure_stream.js';
import { confirmDialog } from '../confirm.js';
import { softNavigate } from '../soft_nav.js';

/**
 * sessionStorage bywa niedostepny (prywatne okno Safari, przegladarki TV
 * z wylaczonym storage) - proba odczytu/zapisu rzuca SecurityError zamiast
 * po prostu nie dzialac. Bez tego opakowania caly connect() tego kontrolera
 * wywalal sie na pierwszym wywolaniu, a przycisk "Pelny ekran" zostawal
 * martwy bez zadnego komunikatu.
 */
const safeStorage = {
    get(key) {
        try {
            return sessionStorage.getItem(key);
        } catch {
            return null;
        }
    },
    set(key, value) {
        try {
            sessionStorage.setItem(key, value);
        } catch {
            // Bez pamieci zyczenie hosta zyje tylko do zamkniecia karty - trudno.
        }
    },
    remove(key) {
        try {
            sessionStorage.removeItem(key);
        } catch {
            // jw.
        }
    },
};

/**
 * Tablo na ekranie hosta. Sluchan eventow pokoju i aktualizuje liste druzyn
 * oraz punkty - telewizor nigdy sie nie odswieza recznie.
 */
export default class extends Controller {
    static targets = ['list', 'empty', 'count', 'template', 'connection', 'joinPanel', 'standingsModal',
        'focusMenu', 'teamsToggleLabel'];
    static values = { hub: String, code: String, removeTeamUrl: String, inGame: Boolean };

    connect() {
        /** Trwające przewijanie licznika - jedno na kafelek drużyny. */
        this.counters = new Map();
        // Czy EventSource już raz się otworzył - drugie (i kolejne) 'online'
        // znaczy, że połączenie się zerwało i wróciło, a hub Mercure nie ma
        // historii: eventy z tego okna przepadły bezpowrotnie.
        this.everConnected = false;

        this.stream = new MercureStream(this.hubValue, {
            onEvent: (event) => this.#handle(event),
            onState: (state) => this.#showConnection(state),
        });
        this.stream.connect();

        this.onKey = (event) => {
            if ('Escape' === event.key) {
                // Esc = host świadomie wychodzi z pełnego ekranu; kasujemy
                // życzenie, żeby kolejny ekran już go nie wciągał z powrotem.
                safeStorage.remove('tvFull');
                this.#focus(false);
                this.closeStandings();
                this.closeFocusMenu();
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

        this.#restoreFullscreen();
        this.#setTeamsPanelVisible('1' === safeStorage.get('tvFocusShowTeams'));
    }

    disconnect() {
        this.stream?.disconnect();
        window.removeEventListener('keydown', this.onKey);
        document.removeEventListener('fullscreenchange', this.onFullscreen);

        if (this.reFull) {
            window.removeEventListener('pointerdown', this.reFull, true);
            window.removeEventListener('keydown', this.reFull, true);
        }
    }

    /**
     * Chowa pasek u góry i punktację z boku - zostaje sama scena gry.
     *
     * Punkty nadal się liczą; znika tylko ich podgląd, więc wyjście musi być
     * widoczne przez cały czas (przycisk w rogu).
     */
    toggleFocus() {
        const on = !this.element.classList.contains('tv-focus');

        // Zapamiętujemy życzenie hosta na cały wieczór (sessionStorage przeżywa
        // przeładowania w tej samej karcie). Przy każdej zmianie ekranu - start
        // gry (reload), powrót do wyboru (redirect) - przeglądarka wychodzi
        // z pełnego ekranu, a bez gestu nie wolno go włączyć z powrotem. Dzięki
        // fladze wracamy do niego przy pierwszym dotknięciu nowego ekranu.
        if (on) {
            safeStorage.set('tvFull', '1');
        } else {
            safeStorage.remove('tvFull');
        }

        this.#focus(on);
    }

    /**
     * Odtwarza pełny ekran po zmianie ekranu, jeśli host go wcześniej włączył.
     *
     * Przeglądarka wychodzi z pełnego ekranu przy każdej nawigacji i nie pozwala
     * włączyć go z powrotem bez gestu użytkownika (zabezpieczenie - inaczej
     * dowolna strona porywałaby ekran). Dlatego czekamy na pierwsze dotknięcie
     * albo klawisz na nowym ekranie i dopiero wtedy wracamy do pełnego ekranu.
     * Dla hosta przy telewizorze jest to praktycznie bezszwowe: i tak zaraz
     * w coś klika, żeby prowadzić grę.
     */
    #restoreFullscreen() {
        if ('1' !== safeStorage.get('tvFull')) {
            return;
        }

        // Pełny ekran przetrwał miękką nawigację (soft_nav.js) - został tylko
        // tryb skupienia (chowanie paska), który żyje na klasie i ginie razem
        // z podmienionym <body>. Dokładamy go od razu, bez czekania na gest.
        if (document.fullscreenElement) {
            this.element.classList.add('tv-focus');

            return;
        }

        // Twarda nawigacja (wpisany adres, tablica w tej samej karcie) wyrzuciła
        // z pełnego ekranu - włączyć go z powrotem wolno tylko z gestu, więc
        // czekamy na pierwsze dotknięcie albo klawisz.
        this.reFull = (event) => {
            // Esc ma wyprowadzać z pełnego ekranu, więc nie traktujemy go jako
            // gestu do powrotu - obsłuży go onKey.
            if ('keydown' === event.type && 'Escape' === event.key) {
                return;
            }

            window.removeEventListener('pointerdown', this.reFull, true);
            window.removeEventListener('keydown', this.reFull, true);

            if ('1' === safeStorage.get('tvFull') && !document.fullscreenElement) {
                this.#focus(true);
            }
        };

        // Capture, żeby złapać gest zanim pochłonie go przycisk gry.
        window.addEventListener('pointerdown', this.reFull, true);
        window.addEventListener('keydown', this.reFull, true);
    }

    #focus(on) {
        if (on === this.element.classList.contains('tv-focus')) {
            return;
        }

        this.element.classList.toggle('tv-focus', on);

        if (!on) {
            // Pasek wrócił - menu zastępcze nie ma już czego zastępować.
            this.closeFocusMenu();
        }

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
     * Wyniki drużyn/zawodników w pełnym ekranie - domyślnie znikają razem
     * z paskiem (maksimum miejsca dla samej gry), ale to osobne życzenie
     * hosta niż sam pełny ekran: czasem chce immersyjny widok bez adresu
     * przeglądarki, a mimo to widzieć tablo z boku. Zapamiętane na tę samą
     * sesję co "tvFull" (sessionStorage), więc przeżywa zmianę ekranu.
     */
    toggleTeamsPanel() {
        const on = !this.element.classList.contains('tv-focus-show-teams');

        if (on) {
            safeStorage.set('tvFocusShowTeams', '1');
        } else {
            safeStorage.remove('tvFocusShowTeams');
        }

        this.#setTeamsPanelVisible(on);
    }

    #setTeamsPanelVisible(on) {
        this.element.classList.toggle('tv-focus-show-teams', on);

        if (this.hasTeamsToggleLabelTarget) {
            this.teamsToggleLabelTarget.textContent = on ? '👥 Ukryj wyniki' : '👥 Pokaż wyniki';
        }
    }

    /**
     * Menu w trybie pełnego ekranu: pasek u góry (Zmień grę, Reset, Tablica
     * wyników, Dźwięk...) jest wtedy schowany razem z resztą, więc te akcje
     * potrzebują osobnego, małego wejścia w rogu - inaczej jedynym wyjściem
     * z pełnego ekranu byłoby najpierw z niego wyjść.
     */
    toggleFocusMenu() {
        if (this.hasFocusMenuTarget) {
            this.focusMenuTarget.hidden = !this.focusMenuTarget.hidden;
        }
    }

    closeFocusMenu() {
        if (this.hasFocusMenuTarget) {
            this.focusMenuTarget.hidden = true;
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
     * Wyniki jako okienko modal nad ekranem. Lista w środku to drugi target
     * `list` - kontroler aktualizuje wszystkie listy naraz, więc modal jest
     * żywy tak samo jak tablo z boku.
     */
    openStandings() {
        if (this.hasStandingsModalTarget) {
            this.standingsModalTarget.hidden = false;
            // Poukładaj od razu wg aktualnych punktów - ukryta lista nie liczy
            // pozycji przez FLIP, więc przy otwarciu wyrównujemy kolejność.
            this.listTargets.forEach((list) => this.#reorder(list));
        }
    }

    closeStandings() {
        if (this.hasStandingsModalTarget) {
            this.standingsModalTarget.hidden = true;
        }
    }

    /** Klik w kartę nie może zamykać modala (tło zamyka, karta nie). */
    stop(event) {
        event.stopPropagation();
    }

    /**
     * Usuwa drużynę z pokoju - host wyrzuca kogoś, kto dołączył przez pomyłkę
     * albo wyszedł z imprezy. Kafelek znika dopiero po potwierdzonym
     * rozgłoszeniu 'team_removed' (patrz #handle), nie od razu po kliknięciu -
     * ten sam wzorzec co reszta tego kontrolera: serwer rozstrzyga, ekran
     * tylko odzwierciedla.
     */
    async removeTeam(event) {
        const tile = event.currentTarget.closest('[data-team-id]');
        const teamId = tile?.dataset.teamId;

        if (!teamId) {
            return;
        }

        const name = tile.querySelector('.team-name')?.textContent?.trim() ?? 'tę drużynę';

        if (!(await confirmDialog(`Usunąć „${name}” z pokoju? Straci dostęp do gry na swoim telefonie.`, { confirmLabel: 'Usuń' }))) {
            return;
        }

        try {
            await fetch(this.removeTeamUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ teamId }),
            });
        } catch {
            // Brak połączenia - drużyna po prostu zostaje, host spróbuje ponownie.
        }
    }

    #handle(event) {
        // Koniec gry z innego urządzenia: ekran gry wraca do lobby i NIE dostaje
        // tego zdarzenia, bo każdy panel gry odpowiadał na 'phase' zapytaniem o
        // widok gry, którego już nie ma (400 w logu przy każdej zmianie gry).
        if ('phase' === event.type && 'lobby' === event.state && this.inGameValue) {
            // Miękko, żeby pełny ekran przetrwał (patrz soft_nav.js).
            softNavigate();

            return;
        }

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
            case 'team_removed':
                this.#removeTeamTile(event.teamId);
                break;
        }
    }

    #removeTeamTile(teamId) {
        this.listTargets.forEach((list) => {
            this.#tileIn(list, teamId)?.remove();
        });

        this.#refreshCount();
        this.listTargets.forEach((list) => this.#renumber(list));
    }

    #addTeam(team, score) {
        if (!team?.id) {
            return;
        }

        // Kafelek trafia do każdej listy (boczne tablo i modal). Event moze
        // przyjsc drugi raz (reconnect SSE odtwarza historie), wiec dokladamy
        // tylko tam, gdzie tej druzyny jeszcze nie ma.
        this.listTargets.forEach((list) => {
            if (this.#tileIn(list, team.id)) {
                return;
            }

            const tile = this.templateTarget.content.firstElementChild.cloneNode(true);
            tile.dataset.teamId = team.id;
            tile.style.setProperty('--team-color', team.color);
            tile.querySelector('.team-name').textContent = team.name;
            tile.querySelector('[data-score]').textContent = String(score);

            list.append(tile);
        });

        this.#refreshCount();
        this.listTargets.forEach((list) => this.#reorder(list));
    }

    #setScore(teamId, total) {
        let found = false;

        this.listTargets.forEach((list) => {
            const scoreEl = this.#tileIn(list, teamId)?.querySelector('[data-score]');
            if (!scoreEl) {
                return;
            }

            found = true;
            this.#countTo(scoreEl, total);
            scoreEl.classList.remove('bump');
            void scoreEl.offsetWidth; // restart animacji przy kolejnym punkcie z rzedu
            scoreEl.classList.add('bump');
        });

        if (found) {
            this.listTargets.forEach((list) => this.#reorder(list));
        }
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
    #reorder(list) {
        const tiles = [...list.children];

        if (tiles.length < 2) {
            this.#renumber(list);

            return;
        }

        const before = new Map(tiles.map((tile) => [tile, tile.getBoundingClientRect().top]));

        // Malejąco po punktach; przy remisie zostaje dotychczasowa kolejność,
        // żeby kafelki nie skakały bez powodu przy każdym punkcie.
        const sorted = [...tiles].sort((a, b) => this.#scoreOf(b) - this.#scoreOf(a));
        sorted.forEach((tile) => list.append(tile));

        this.#renumber(list);

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

    #renumber(list) {
        [...list.children].forEach((tile, index) => {
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
        this.listTargets.forEach((list) => {
            list.querySelectorAll('[data-score]').forEach((el) => {
                el.textContent = '0';
            });

            this.#renumber(list);
        });
    }

    #tileIn(list, teamId) {
        return list.querySelector(`[data-team-id="${CSS.escape(teamId)}"]`);
    }

    #refreshCount() {
        const total = this.listTargets[0]?.children.length ?? 0;

        if (this.hasCountTarget) {
            this.countTarget.textContent = `(${total})`;
        }
        if (this.hasEmptyTarget) {
            this.emptyTarget.hidden = total > 0;
        }
    }

    #showConnection(state) {
        // Reconnect po realnym zerwaniu: hub nie ma historii (transport local),
        // więc każdy event z tego okna przepadł. Panele gier same nie mają jak
        // się o tym dowiedzieć - stąd sygnał "odśwież się", niezależnie od
        // tego, czy przerwa zdążyła pokazać się jako 'offline', czy tylko jako
        // krótkie 'connecting' w tle (EventSource re-łączy się sam z backoffem).
        if ('online' === state) {
            if (this.everConnected) {
                window.dispatchEvent(new CustomEvent('gierki:event', { detail: { type: 'resync' } }));
            }
            this.everConnected = true;
        }

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
