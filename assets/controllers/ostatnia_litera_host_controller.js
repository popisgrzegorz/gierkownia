import { Controller } from '@hotwired/stimulus';
import { sfx } from '../audio/sfx.js';

/**
 * Panel Ostatniej litery na ekranie hosta.
 *
 * Wymagana litera na ekranie jest tylko podpowiedzią - liczy się wyłącznie
 * to, co sprawdzi serwer przy "submit". Tak samo zegar: odliczamy lokalnie
 * z pary {deadline, serverNow}, ale o upływie czasu i tak rozstrzyga
 * GameTimer::expired() po stronie serwera.
 */
export default class extends Controller {
    static targets = ['clock', 'clockValue', 'letter', 'seconds', 'startingPoints', 'winThreshold',
        'secondsRow', 'thresholdLine', 'turn', 'wordChain', 'event', 'judgePanel', 'judgeTeam',
        'judgeWord', 'teams', 'controls', 'hint'];
    static values = { actionUrl: String, viewUrl: String };

    /** Miekkie spolgloski koncowe i ich dwuznak - sama podpowiedz na ekranie, nie walidacja. */
    static DIGRAPHS = { ń: 'NI', ć: 'CI', ś: 'SI', ź: 'ZI' };

    /** Żadne polskie słowo nie zaczyna się na „ą" - po nim wymagane jest „a" (patrz serwer, NASAL_FALLBACKS). */
    static NASALS = { ą: 'a', ę: 'e' };

    connect() {
        this.offset = 0;
        this.teams = new Map(
            [...document.querySelectorAll('[data-team-id]')].map((tile) => [
                tile.dataset.teamId,
                tile.querySelector('.team-name')?.textContent?.trim() ?? 'drużyna',
            ]),
        );

        this.onEvent = (event) => this.#onServerEvent(event.detail);
        window.addEventListener('gierki:event', this.onEvent);

        this.refresh();
    }

    disconnect() {
        window.removeEventListener('gierki:event', this.onEvent);
        clearInterval(this.ticker);
        clearTimeout(this.timeoutGrace);
    }

    async refresh() {
        const requestId = (this.refreshSeq = (this.refreshSeq ?? 0) + 1);
        const response = await fetch(this.viewUrlValue, { headers: { Accept: 'application/json' } });

        if (requestId !== this.refreshSeq) {
            return;
        }

        if (response.ok) {
            this.#render(await response.json());
        }
    }

    async act(event) {
        const button = event.currentTarget;

        if (button.disabled) {
            return;
        }

        const action = button.dataset.olAction;
        const payload = 'start' === action ? {
            seconds: Number(this.secondsTarget.value),
            startingPoints: Number(this.startingPointsTarget.value),
            winThreshold: Number(this.winThresholdTarget.value),
        } : {};

        button.disabled = true;

        try {
            await this.#send(action, payload);
        } finally {
            button.disabled = false;
        }
    }

    async rule(event) {
        const accept = 'true' === event.currentTarget.dataset.accept;
        await this.#send('rule', { accept });
    }

    #onServerEvent(event) {
        if ('word_ruled' === event.type) {
            this.#flash(event.accepted ? 'Sędzia zaakceptował słowo.' : 'Sędzia odrzucił słowo.');
        }

        if ('word_duplicate' === event.type) {
            this.#flash(`${this.#teamName(event.teamId)}: „${event.word}” już padło w tej grze!`);
            sfx.play('wrong');
        }

        if ('turn_missed' === event.type) {
            this.#flash(`${this.#teamName(event.teamId)} traci punkty za spóźnienie.`);
        }

        if ('word_accepted' === event.type || ('word_ruled' === event.type && event.accepted)) {
            this.#flash(`${this.#teamName(event.teamId)} +${event.points} pkt!`);
        }

        if ('team_eliminated' === event.type) {
            this.#flash(`${this.#teamName(event.teamId)} odpada z gry!`);
        }

        if ('game_over' === event.type) {
            this.#flash(event.winner ? `Zwycięzca: ${this.#teamName(event.winner)}!` : 'Koniec gry.');
        }

        if (['phase', 'timer', 'game_started', 'word_accepted', 'word_pending', 'word_ruled',
            'turn_missed', 'team_eliminated', 'game_over', 'team_joined', 'reset', 'resync'].includes(event.type)) {
            if ('team_joined' === event.type) {
                this.teams.set(event.team.id, event.team.name);
            }

            this.refresh();
        }
    }

    #render(view) {
        this.secondsRowTarget.hidden = 'idle' !== view.state;

        this.thresholdLineTarget.textContent = 'idle' !== view.state
            ? `Start: ${view.startingPoints} pkt — próg zwycięstwa: ${view.winThreshold} pkt`
            : '';

        // „Zagraj ponownie" zostawia w stanie dane skończonej gry (start() i tak
        // zeruje wszystko), więc w idle nie pokazujemy ani liter, ani łańcucha słów.
        const idle = 'idle' === view.state;

        this.letterTarget.textContent = this.#requiredLetterDisplay(idle ? null : view.lastWord);
        this.turnTarget.textContent = 'playing' === view.state && view.turn
            ? `Teraz: ${this.#teamName(view.turn)}`
            : '';
        this.#renderWordChain(idle ? [] : (view.usedWords ?? []));

        this.judgePanelTarget.hidden = 'judging' !== view.state;

        if ('judging' === view.state) {
            this.judgeTeamTarget.textContent = view.turn ? this.#teamName(view.turn) : 'Drużyna';
            this.judgeWordTarget.textContent = view.pendingWord ?? '';
        }

        this.#renderTeams(view);
        this.#syncClock(view);
        this.#renderControls(view);
    }

    /** Slowa po kolei - ostatnie wyrozniamy, zeby bylo widac, od czego liczy sie kolejna litera. */
    #renderWordChain(words) {
        this.wordChainTarget.replaceChildren(...words.map((word, index) => {
            const li = document.createElement('li');
            li.className = 'ol-word-chain-item';
            li.classList.toggle('ol-word-chain-current', index === words.length - 1);
            li.textContent = word;

            return li;
        }));
    }

    #renderTeams(view) {
        // W idle (po „Zagraj ponownie") wyniki i „odpadła" są jeszcze z poprzedniej gry.
        const idle = 'idle' === view.state;
        const points = view.points ?? {};
        const eliminated = new Set(idle ? [] : (view.eliminated ?? []));

        const items = (view.teams ?? []).map((team) => {
            const li = document.createElement('li');
            li.className = 'ol-team';
            li.classList.toggle('ol-team-active', team.id === view.turn && 'playing' === view.state);
            li.classList.toggle('ol-team-out', eliminated.has(team.id));

            const name = document.createElement('span');
            name.className = 'ol-team-name';
            name.textContent = team.name;

            const score = document.createElement('span');
            score.className = 'ol-team-credits';
            score.textContent = idle ? '—' : (eliminated.has(team.id) ? 'odpadła' : `${points[team.id] ?? 0} pkt`);

            li.append(name, score);

            return li;
        });

        this.teamsTarget.replaceChildren(...items);
    }

    #renderControls(view) {
        const buttons = {
            idle: [['start', '▶ Start', 'primary']],
            finished: [['restart', 'Zagraj ponownie', 'primary']],
        }[view.state] ?? [];

        this.controlsTarget.replaceChildren(...buttons.map(([action, label, variant]) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `btn btn-lg${'primary' === variant ? ' btn-primary' : ''}`;
            button.textContent = label;
            button.dataset.olAction = action;
            button.dataset.action = 'ostatnia-litera-host#act';

            return button;
        }));

        this.hintTarget.textContent = {
            idle: 'Wybierz czas na słowo i zaczynajcie.',
            playing: 'Odliczanie widzą wszyscy - słowo wpisuje ta drużyna, której jest kolej.',
            judging: 'Słownik nie rozpoznał słowa - rozstrzygnij, czy jest poprawne.',
            finished: view.winner ? `Wygrywa: ${this.#teamName(view.winner)}.` : 'Koniec gry.',
        }[view.state] ?? '';
    }

    #syncClock(view) {
        clearInterval(this.ticker);
        clearTimeout(this.timeoutGrace);

        if (!view.deadline || 'playing' !== view.state) {
            this.clockTarget.hidden = true;

            return;
        }

        this.offset = view.serverNow - Date.now();
        this.clockTarget.hidden = false;

        const tick = () => {
            const left = Math.max(0, view.deadline - (Date.now() + this.offset));
            this.clockValueTarget.textContent = Math.ceil(left / 1000);
            this.clockTarget.dataset.state = left < 3_000 ? 'urgent' : 'running';

            if (0 === left) {
                clearInterval(this.ticker);
                // Bufor na opóźnienie sieciowe realnego zgłoszenia gracza - telefon
                // na wolniejszej sieci (mobilnej) może zdążyć kliknąć "Wyślij"
                // przed czasem, ale jego POST dotrze do serwera z opóźnieniem.
                // Bez tego bufora zgłoszenie hosta (często z szybszej sieci/wifi)
                // wygrywa wyścig o blokadę pokoju, mimo że gracz naprawdę zdążył.
                // Serwer i tak rozstrzyga naprawdę (GameTimer::expired()) - to
                // tylko odsuwa moment, w którym HOST *zgłasza* upływ czasu.
                this.timeoutGrace = setTimeout(() => this.#send('timeout'), 700);
            }
        };

        // Interwal PRZED pierwszym tick() - inaczej dwa prawie-jednoczesne
        // wywołania (ten i kolejny #syncClock po odświeżeniu) mogłyby obie
        // zdążyć zobaczyć left===0 i wysłać 'timeout' dwukrotnie.
        this.ticker = setInterval(tick, 200);
        tick();
    }

    /** Podpowiedź na ekranie - sama walidacja słowa jest po stronie serwera. */
    #requiredLetterDisplay(lastWord) {
        if (!lastWord) {
            return '?';
        }

        const last = lastWord.slice(-1).toLowerCase();
        const pair = this.constructor.DIGRAPHS[last];

        return pair ? `${last.toUpperCase()} / ${pair}` : (this.constructor.NASALS[last] ?? last).toUpperCase();
    }

    #teamName(teamId) {
        return this.teams.get(teamId) ?? 'drużyna';
    }

    #flash(text) {
        this.eventTarget.textContent = text;
        clearTimeout(this.flashTimer);
        this.flashTimer = setTimeout(() => { this.eventTarget.textContent = ''; }, 4000);
    }

    async #send(action, payload = {}) {
        const response = await fetch(this.actionUrlValue, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ action, payload }),
        });

        if (response.ok) {
            this.refresh();
        }
    }
}
