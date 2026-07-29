import { Controller } from '@hotwired/stimulus';
import gsap from 'gsap';
import { sfx } from '../audio/sfx.js';
import { popIn } from '../anim.js';

/**
 * Panel państw i miast na ekranie hosta.
 *
 * Dwa tryby: w trakcie pisania widać wyłącznie literę i postęp drużyn,
 * po rundzie - tabelę odpowiedzi, w której każda komórka jest przyciskiem
 * uznającym albo odrzucającym odpowiedź.
 *
 * Odliczanie liczymy z pary {deadline, serverNow}: zegar telewizora bywa
 * przestawiony, a licznik musi zgadzać się z tym, co widzą telefony.
 */
/* Litery przewijane w trakcie losowania - te same, z których losuje serwer. */
const ROLL = [...'ABCDEFGHIJKLMNOPRSTUWZ'];

/* Ile trwa wyhamowanie po naciśnięciu STOP - dopiero wtedy litera zapada. */
const BRAKE_MS = 1400;

export default class extends Controller {
    static targets = ['letterBox', 'letter', 'clock', 'clockValue', 'tip', 'progressLine',
        'progress', 'results', 'event', 'setupRow', 'preset', 'seconds', 'controls', 'hint'];
    static values = { actionUrl: String, viewUrl: String };

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
        clearTimeout(this.rollTimer);
    }

    async refresh() {
        // Patrz komentarz w analogicznym miejscu dutch_host_controller.js -
        // ignorujemy odpowiedź, jeśli w międzyczasie wystartował nowszy refresh().
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

        button.disabled = true;

        try {
            await this.#send(button.dataset.panstwaAction);
        } finally {
            button.disabled = false;
        }
    }

    /**
     * Rusza przewijanie liter. Serwer NIC jeszcze nie losuje - litera zapada
     * dopiero po STOP-ie, tak jak przy losowaniu z bębna: najpierw kręci się
     * w kółko, a wynik pojawia się w chwili zatrzymania.
     */
    startRoll() {
        if (this.rolling) {
            return;
        }

        this.rolling = true;
        this.landing = null;
        this.letterBoxTarget.hidden = false;
        this.letterBoxTarget.classList.add('rolling');
        this.#renderControls({ state: 'idle' });
        this.#rollStep();
    }

    /**
     * STOP: to dopiero tutaj serwer losuje literę.
     *
     * Przewijanie kręci się bez końca, więc gdyby litera zapadała na starcie,
     * host mógłby ją odczytać z odpowiedzi HTTP zanim zwolni przycisk.
     */
    async stopRoll() {
        if (!this.rolling || this.stopping) {
            return;
        }

        this.stopping = true;

        // Ustawienia lecą tuż przed losowaniem: host zwykle zmienia je
        // dopiero wtedy, gdy widzi, że poprzednia runda była za krótka.
        await this.#send('configure', {
            preset: this.presetTarget.value,
            seconds: Number(this.secondsTarget.value),
        });

        const drawn = await this.#send('draw');

        this.stopping = false;

        // Serwer odmówił (np. nie ma jeszcze drużyn) - bęben musi stanąć,
        // inaczej kręciłby się w nieskończoność nad pustym stołem.
        if (!drawn) {
            this.#stopRolling();
            this.letterBoxTarget.hidden = true;
            this.refresh();
        }
    }

    /** Klik w komórkę: uznaje odpowiedź albo ją odrzuca. */
    async toggle(event) {
        const cell = event.currentTarget;

        await this.#send('toggle', { teamId: cell.dataset.teamId, column: cell.dataset.column });
    }

    #onServerEvent(event) {
        if ('letter_drawn' === event.type) {
            // Litera zapadła na serwerze - bęben zaczyna hamować.
            this.#rollLetter(event.letter);
        }

        if ('stopped' === event.type) {
            this.#flash(`STOP: ${event.teamName}`);
            sfx.play('whistle');
        }

        if ('awarded' === event.type) {
            this.#flash('Punkty poszły na tablo');
            sfx.play('victory');
        }

        if (['phase', 'timer', 'letter_drawn', 'stopped', 'results', 'awarded',
            'progress', 'configured', 'round_reset', 'team_joined', 'reset', 'resync'].includes(event.type)) {
            if ('team_joined' === event.type) {
                this.teams.set(event.team.id, event.team.name);
            }

            const done = this.refresh();

            // Tabela wyników wskakuje z powiększenia - cały ekran „siada" w wynik.
            if ('results' === event.type) {
                done.then(() => popIn(this.resultsTarget, { scale: 0.85, duration: 0.45 }));
            }
        }
    }

    #render(view) {
        const writing = ['writing', 'ending'].includes(view.state);

        // Stan trafia na element: to po nim CSS przestawia ekran punktacji,
        // gdzie czyta się tabelę, a nie literę.
        this.element.dataset.state = view.state;

        // W trakcie przewijania litery jeszcze nie ma, a bęben musi być widoczny.
        this.letterBoxTarget.hidden = !view.letter && !this.rolling;

        // W trakcie przewijania nie dotykamy litery - inaczej odświeżenie
        // widoku wywaliłoby wynik na ekran w połowie animacji.
        if (!this.rolling) {
            this.letterTarget.textContent = view.letter ?? '?';
        }

        this.setupRowTarget.hidden = 'idle' !== view.state;
        this.#fillPresets(view.presets ?? [], view.preset);

        this.progressLineTarget.textContent = view.round
            ? `Runda ${view.round} — ${view.columns.length} rubryk`
            : '';

        this.progressTarget.hidden = !writing;
        this.resultsTarget.hidden = 'scoring' !== view.state;

        if (writing) {
            this.#renderProgress(view);
        }

        if ('scoring' === view.state) {
            this.#renderResults(view);
        }

        this.tipTarget.hidden = 'scoring' === view.state;
        this.#syncClock(view);
        this.#renderControls(view);
    }

    /** Ile rubryk wypełniła każda drużyna - bez treści, ta jest tajna. */
    #renderProgress(view) {
        const total = view.columns.length;

        this.progressTarget.replaceChildren(...[...this.teams.entries()].map(([id, name]) => {
            const filled = view.progress?.[id] ?? 0;

            const row = document.createElement('div');
            row.className = 'pm-progress-row';

            const label = document.createElement('span');
            label.className = 'pm-progress-name';
            label.textContent = name;

            const dots = document.createElement('span');
            dots.className = 'pm-dots';

            for (let i = 0; i < total; i += 1) {
                const dot = document.createElement('span');
                dot.className = i < filled ? 'pm-dot pm-dot-on' : 'pm-dot';
                dots.append(dot);
            }

            const count = document.createElement('span');
            count.className = 'pm-progress-count';
            count.textContent = `${filled}/${total}`;

            row.append(label, dots, count);

            return row;
        }));
    }

    #renderResults(view) {
        const results = view.results;

        if (!results) {
            this.resultsTarget.replaceChildren();

            return;
        }

        const table = document.createElement('table');
        table.className = 'pm-table';

        const head = document.createElement('tr');
        head.append(this.#th(''));
        view.columns.forEach((column) => head.append(this.#th(column)));
        head.append(this.#th('Σ'));
        table.append(head);

        (view.teams ?? []).forEach((team) => {
            const row = document.createElement('tr');

            const name = document.createElement('th');
            name.className = 'pm-team';
            name.textContent = team.name;
            name.style.setProperty('--team-color', team.color);
            row.append(name);

            view.columns.forEach((column) => {
                const cell = results.cells?.[team.id]?.[column] ?? { text: '', points: 0, status: 'empty' };
                row.append(this.#cell(team.id, column, cell));
            });

            const total = document.createElement('td');
            total.className = 'pm-total';
            total.textContent = results.totals?.[team.id] ?? 0;
            row.append(total);

            table.append(row);
        });

        this.resultsTarget.replaceChildren(table);
    }

    #cell(teamId, column, cell) {
        const td = document.createElement('td');
        td.className = `pm-cell pm-${cell.status}`;
        td.dataset.teamId = teamId;
        td.dataset.column = column;

        if (cell.text) {
            // Pusta rubryka nie jest do uznania - klikanie w nią tylko myli.
            // role="button" + tabindex + Enter/Spacja: <td> samo z siebie nie
            // jest osiągalne z klawiatury, a host ocenia odpowiedzi tylko
            // myszą, jeśli tego zabraknie (P2-24 audytu).
            td.dataset.action = 'click->panstwa-host#toggle keydown.enter->panstwa-host#toggle keydown.space->panstwa-host#toggle:prevent';
            td.setAttribute('role', 'button');
            td.setAttribute('tabindex', '0');
            td.title = {
                too_short: 'Jedna litera to nie odpowiedź. Kliknij, jeśli mimo to uznajesz',
                unknown: 'Nie ma tego na liście państw i miast — kliknij, żeby uznać',
                wrong_letter: 'Inna litera niż wylosowana. Kliknij, jeśli mimo to uznajesz',
            }[cell.status] ?? 'Kliknij, żeby uznać albo odrzucić';
        }

        const text = document.createElement('span');
        text.className = 'pm-text';
        text.textContent = cell.text || '—';

        const points = document.createElement('span');
        points.className = 'pm-points';
        points.textContent = cell.points;

        td.append(text, points);

        if (cell.forced) {
            // Ślad po decyzji hosta - żeby po chwili było widać, co poprawiał.
            td.classList.add('pm-forced');
        }

        return td;
    }

    #th(text) {
        const th = document.createElement('th');
        th.textContent = text;

        return th;
    }

    #fillPresets(presets, current) {
        if (this.presetTarget.options.length === presets.length) {
            return;
        }

        this.presetTarget.replaceChildren(...presets.map((preset) => {
            const option = document.createElement('option');
            option.value = preset.key;
            option.textContent = `${preset.name} (${preset.columns})`;
            option.selected = preset.key === current;

            return option;
        }));
    }

    #syncClock(view) {
        clearInterval(this.ticker);

        if (!view.deadline || 'ending' !== view.state) {
            this.clockTarget.hidden = true;

            return;
        }

        this.offset = view.serverNow - Date.now();
        this.clockTarget.hidden = false;

        const tick = () => {
            const left = Math.max(0, view.deadline - (Date.now() + this.offset));
            this.clockValueTarget.textContent = Math.ceil(left / 1000);
            this.clockTarget.dataset.state = left < 10_000 ? 'urgent' : 'running';

            if (0 === left) {
                clearInterval(this.ticker);
                // Czas minął - host melduje koniec, a serwer to weryfikuje.
                this.#send('finish');
            }
        };

        // Interwal PRZED pierwszym tick() - patrz komentarz w analogicznym
        // miejscu quiz_host_controller.js (ten sam wyścig o podwójne 'finish').
        this.ticker = setInterval(tick, 200);
        tick();
    }

    #renderControls(view) {
        if (this.rolling) {
            // W czasie przewijania jedyny sensowny przycisk to STOP.
            const stop = document.createElement('button');
            stop.type = 'button';
            stop.className = 'btn btn-lg btn-primary';
            stop.textContent = 'STOP';
            stop.dataset.action = 'panstwa-host#stopRoll';

            this.controlsTarget.replaceChildren(stop);
            this.hintTarget.textContent = 'Naciśnij STOP - wtedy zapada litera.';

            return;
        }

        if ('idle' === view.state) {
            const spin = document.createElement('button');
            spin.type = 'button';
            spin.className = 'btn btn-lg btn-primary';
            spin.textContent = 'Losuj literę';
            spin.dataset.action = 'panstwa-host#startRoll';

            this.controlsTarget.replaceChildren(spin);
            this.hintTarget.textContent = 'Rusz bębnem - zatrzymasz go przyciskiem STOP.';

            return;
        }

        const buttons = {
            writing: [['stop', 'STOP', 'primary'], ['finish', 'Kończ rundę', '']],
            ending: [],
            scoring: view.awarded
                ? [['next_round', 'Następna runda', 'primary']]
                : [['award', 'Zalicz punkty', 'primary'], ['next_round', 'Pomiń punkty', '']],
        }[view.state] ?? [];

        this.controlsTarget.replaceChildren(...buttons.map(([action, label, variant]) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `btn btn-lg${'primary' === variant ? ' btn-primary' : ''}`;
            button.textContent = label;
            button.dataset.panstwaAction = action;
            button.dataset.action = 'panstwa-host#act';

            return button;
        }));

        this.hintTarget.textContent = {
            idle: 'Wylosuj literę, żeby zacząć rundę.',
            writing: 'Drużyny wpisują na telefonach. STOP naciska ta, która skończy pierwsza.',
            ending: 'Ktoś nacisnął STOP — reszta kończy w czasie na zegarze.',
            scoring: view.awarded
                ? 'Punkty policzone. Czas na kolejną literę.'
                : 'Kliknij odpowiedź, żeby ją uznać albo odrzucić. Potem zalicz punkty.',
        }[view.state] ?? '';
    }

    /**
     * Przewija litery i zatrzymuje się na wylosowanej.
     *
     * Zwalnianie jest sercem tego efektu: równe tempo wygląda jak zepsuty
     * ekran, a dopiero rosnące odstępy dają "jeszcze... jeszcze... stoi".
     */
    #rollLetter(letter) {
        // Litera z serwera przyszła - od tej chwili bęben hamuje. Gdy host
        // otworzył ekran w połowie rundy, przewijania nie ma i litera po
        // prostu stoi.
        if (!this.rolling) {
            return;
        }

        this.landing = letter;
        this.landingStart = performance.now();
    }

    /**
     * Jeden krok przewijania.
     *
     * Zwalnianie jest sercem tego efektu: równe tempo wygląda jak zepsuty
     * ekran, a dopiero rosnące odstępy dają "jeszcze... jeszcze... stoi".
     */
    #rollStep() {
        // Na pełnym biegu litery lecą co 45 ms i klik z każdej zlewa się
        // w brzęczenie - stąd co drugi krok. Przy hamowaniu odstępy rosną
        // same, więc tam gra już każdy: bęben zwalnia i w oku, i w uchu.
        this.rollBeat = (this.rollBeat ?? 0) + 1;

        if (null !== this.landing || 0 === this.rollBeat % 2) {
            sfx.play('drum');
        }

        if (null === this.landing) {
            this.#flip(ROLL[Math.floor(Math.random() * ROLL.length)], 0.05);
            this.rollTimer = setTimeout(() => this.#rollStep(), 45);

            return;
        }

        const progress = Math.min(1, (performance.now() - this.landingStart) / BRAKE_MS);

        if (1 === progress) {
            this.#stopRolling();
            // Ostatnia klapka opada wolniej i mocniej - to ona ma zostać w oku.
            this.#flip(this.landing, 0.55, 'back.out(1.6)');
            this.letterBoxTarget.classList.add('landed');
            // Litera zapadła - pieczątka i dzwonek. Nie w #stopRolling(), bo
            // tamto woła też odmowa serwera, a wtedy nic nie wylosowano.
            sfx.play('stamp');
            setTimeout(() => this.letterBoxTarget.classList.remove('landed'), 600);
            this.refresh();

            return;
        }

        const delay = 45 + 215 * progress ** 2;

        // Klapka opada tym dłużej, im wolniej kręci się bęben - obrót nadąża
        // za rytmem, więc przy hamowaniu każdą literę widać, jak się przewraca.
        this.#flip(ROLL[Math.floor(Math.random() * ROLL.length)], (delay / 1000) * 0.9);

        // Od 45 ms tuż po STOP-ie do ~260 ms przed zatrzymaniem.
        this.rollTimer = setTimeout(() => this.#rollStep(), delay);
    }

    /**
     * Przewrócenie litery jak klapka na dworcowej tablicy: nowa litera spada
     * z góry, obracając się wokół górnej krawędzi. Przy reduced-motion litera
     * po prostu się podmienia - bez obrotu.
     */
    #flip(letter, duration, ease = 'power1.out') {
        this.letterTarget.textContent = letter;

        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        gsap.fromTo(
            this.letterTarget,
            { rotationX: -92 },
            {
                rotationX: 0,
                duration: Math.max(0.04, duration),
                transformOrigin: '50% 0%',
                transformPerspective: 600,
                ease,
                overwrite: true,
            },
        );
    }

    #stopRolling() {
        clearTimeout(this.rollTimer);
        this.rolling = false;
        this.landing = null;
        this.letterBoxTarget.classList.remove('rolling');
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

            return true;
        }

        const problem = await response.json().catch(() => ({}));
        this.hintTarget.textContent = problem.detail ?? 'Nie udało się wykonać akcji.';

        return false;
    }
}
