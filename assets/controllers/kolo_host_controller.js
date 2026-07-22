import { Controller } from '@hotwired/stimulus';

const SPIN_MS = 3200;

/**
 * Panel koła fortuny na ekranie hosta.
 *
 * Koło **nie losuje**. Wynik zapada na serwerze (spec 14) i przychodzi eventem
 * `wheel_result` z numerem sektora; animacja tylko dokręca tarczę tak, żeby ten
 * sektor zatrzymał się pod wskaźnikiem. Dzięki temu nie da się wygrać punktów
 * przez podmianę czegokolwiek w karcie telewizora.
 */
export default class extends Controller {
    static targets = ['deckPicker', 'deckSelect', 'progress', 'board', 'hintText',
        'wheel', 'turn', 'answer', 'event', 'controls', 'hint'];
    static values = { actionUrl: String, viewUrl: String };

    connect() {
        this.angle = 0;
        this.sectors = [];
        this.teams = new Map();

        this.onEvent = (event) => this.#onServerEvent(event.detail);
        window.addEventListener('gierki:event', this.onEvent);

        this.refresh();
    }

    disconnect() {
        window.removeEventListener('gierki:event', this.onEvent);
        cancelAnimationFrame(this.frame);
    }

    async refresh() {
        const response = await fetch(this.viewUrlValue, { headers: { Accept: 'application/json' } });

        if (response.ok) {
            this.#render(await response.json());
        }
    }

    async pickDeck() {
        await this.#send('pick_deck', { deck: this.deckSelectTarget.value });
    }

    async act(event) {
        await this.#send(event.currentTarget.dataset.koloAction);
    }

    #onServerEvent(event) {
        switch (event.type) {
            case 'wheel_result':
                // Serwer już zna wynik; animacja go tylko pokazuje, a po jej
                // zakończeniu meldujemy zatrzymanie i gra rusza dalej.
                this.#spinTo(event.index).then(() => this.#send('land'));

                return;

            case 'team_joined':
                this.teams.set(event.team.id, event.team);
                break;

            case 'letter_revealed':
                this.#flash(0 === event.count
                    ? `Litery ${event.letter} nie ma`
                    : `${event.letter} × ${event.count}`);
                break;

            case 'penalty':
                this.#flash('bankrut' === event.kind ? 'BANKRUT!' : 'STOP — koniec tury');
                break;

            case 'solve_failed':
                this.#flash('pudło');
                break;

            case 'puzzle_solved':
                this.#flash(`${event.teamName} rozwiązuje! +${event.won}`);
                break;
        }

        if (['phase', 'letter_revealed', 'penalty', 'puzzle_solved', 'puzzle_ready',
            'deck_picked', 'turn', 'solve_failed', 'reset'].includes(event.type)) {
            this.refresh();
        }
    }

    #render(view) {
        this.sectors = view.sectors ?? [];
        this.deckPickerTarget.hidden = Boolean(view.deck);

        if (!view.deck) {
            this.#fillDecks(view.decks ?? []);
        }

        this.progressTarget.textContent = view.deck
            ? `${view.deckName} — hasło ${view.puzzleNumber} z ${view.puzzleTotal}`
            : '';

        this.#renderBoard(view.mask);
        this.hintTextTarget.textContent = [view.category, view.hint].filter(Boolean).join(' • ');
        this.answerTarget.textContent = view.phrase ?? '';
        this.turnTarget.textContent = view.turn ? `Tura: ${this.#teamName(view.turn)}` : '';

        this.#drawWheel();
        this.#renderControls(view);
    }

    #renderBoard(mask) {
        if (!mask) {
            this.boardTarget.replaceChildren();

            return;
        }

        this.boardTarget.replaceChildren(...[...mask].map((char) => {
            const cell = document.createElement('span');
            cell.className = 'puzzle-cell';

            if (' ' === char) {
                cell.classList.add('puzzle-space');
            } else if ('_' === char) {
                cell.classList.add('puzzle-hidden');
            } else {
                cell.textContent = char;
            }

            return cell;
        }));
    }

    #renderControls(view) {
        const buttons = {
            idle: view.deck ? [['next_puzzle', 'Losuj hasło', 'primary']] : [],
            next_team: [['spin', '🎡 Zakręć', 'primary'], ['skip_turn', 'Oddaj turę', '']],
            spinning: [],
            landed: [],
            pick_letter: [['skip_turn', 'Oddaj turę', '']],
            reveal: [],
            solved: [['next_puzzle', 'Następne hasło', 'primary']],
        }[view.state] ?? [];

        this.controlsTarget.replaceChildren(...buttons.map(([action, label, variant]) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `btn btn-lg${'primary' === variant ? ' btn-primary' : ''}`;
            button.textContent = label;
            button.dataset.koloAction = action;
            button.dataset.action = 'kolo-host#act';

            return button;
        }));

        this.hintTarget.textContent = {
            idle: view.deck ? 'Wylosuj hasło, żeby zacząć.' : 'Wybierz talię.',
            next_team: 'Drużyna kręci kołem — z telefonu albo stąd.',
            spinning: 'Koło się kręci…',
            pick_letter: 'Drużyna podaje literę na telefonie.',
            solved: 'Hasło rozwiązane.',
        }[view.state] ?? '';
    }

    #teamName(teamId) {
        return this.teams.get(teamId)?.name ?? 'drużyna';
    }

    #fillDecks(decks) {
        if (this.deckSelectTarget.options.length === decks.length) {
            return;
        }

        this.deckSelectTarget.replaceChildren(...decks.map((deck) => {
            const option = document.createElement('option');
            option.value = deck.slug;
            option.textContent = `${deck.name} (${deck.items})`;

            return option;
        }));
    }

    /** Dokręca tarczę tak, żeby wskazany sektor stanął pod wskaźnikiem u góry. */
    #spinTo(index) {
        const count = this.sectors.length;

        if (!count) {
            return Promise.resolve();
        }

        const sector = (2 * Math.PI) / count;
        // Środek sektora pod wskaźnikiem (-90°), plus kilka pełnych obrotów,
        // żeby zatrzymanie wyglądało na wynik kręcenia, a nie przeskok.
        const target = -Math.PI / 2 - (index + 0.5) * sector;
        const from = this.angle;
        const to = target - 6 * Math.PI - ((target - from) % (2 * Math.PI));

        return new Promise((resolve) => {
            const start = performance.now();

            const step = (now) => {
                const t = Math.min(1, (now - start) / SPIN_MS);
                // easeOutQuart: szybki start, długie dohamowanie - tak zwalnia
                // prawdziwe koło i to ono buduje napięcie przy stole.
                this.angle = from + (to - from) * (1 - (1 - t) ** 4);
                this.#drawWheel();

                if (t < 1) {
                    this.frame = requestAnimationFrame(step);
                } else {
                    resolve();
                }
            };

            this.frame = requestAnimationFrame(step);
        });
    }

    #drawWheel() {
        const canvas = this.wheelTarget;
        const ctx = canvas.getContext('2d');
        const count = this.sectors.length;

        ctx.clearRect(0, 0, canvas.width, canvas.height);

        if (!count) {
            return;
        }

        const cx = canvas.width / 2;
        const cy = canvas.height / 2;
        const r = Math.min(cx, cy) - 14;
        const sector = (2 * Math.PI) / count;

        this.sectors.forEach((value, i) => {
            const start = this.angle + i * sector;

            ctx.beginPath();
            ctx.moveTo(cx, cy);
            ctx.arc(cx, cy, r, start, start + sector);
            ctx.closePath();
            ctx.fillStyle = this.#sectorColor(value, i);
            ctx.fill();

            ctx.save();
            ctx.translate(cx, cy);
            ctx.rotate(start + sector / 2);
            ctx.fillStyle = 'bankrut' === value ? '#fff' : '#10121f';
            ctx.font = '600 18px system-ui, sans-serif';
            ctx.textAlign = 'right';
            ctx.textBaseline = 'middle';
            ctx.fillText(String(value).toUpperCase(), r - 14, 0);
            ctx.restore();
        });

        // Wskaźnik u góry - punkt odniesienia dla wyniku.
        ctx.beginPath();
        ctx.moveTo(cx - 14, 2);
        ctx.lineTo(cx + 14, 2);
        ctx.lineTo(cx, 30);
        ctx.closePath();
        ctx.fillStyle = '#f5f6fb';
        ctx.fill();
    }

    #sectorColor(value, index) {
        if ('bankrut' === value) {
            return '#1b1e2e';
        }
        if ('stop' === value) {
            return '#e5484d';
        }

        return index % 2 ? '#f5a524' : '#ffd27d';
    }

    #flash(text) {
        this.eventTarget.textContent = text;
        this.eventTarget.classList.remove('bump');
        void this.eventTarget.offsetWidth;
        this.eventTarget.classList.add('bump');
    }

    async #send(action, payload = {}) {
        const response = await fetch(this.actionUrlValue, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ action, payload }),
        });

        if (!response.ok) {
            const problem = await response.json().catch(() => ({}));
            this.hintTarget.textContent = problem.detail ?? 'Nie udało się wykonać akcji.';

            return;
        }

        this.refresh();
    }
}
