import { Controller } from '@hotwired/stimulus';
import { sfx } from '../audio/sfx.js';

/**
 * Panel kalambur na ekranie hosta.
 *
 * Odliczanie liczymy z pary {deadline, serverNow}: zegar telewizora bywa
 * przestawiony, a licznik musi zgadzać się z tym, co widzą telefony.
 * Offset liczymy raz, przy odebraniu terminu, i od niego odmierzamy.
 */
export default class extends Controller {
    static targets = ['categoryPicker', 'categorySelect', 'progress', 'board', 'turn',
        'answer', 'event', 'controls', 'hint', 'clock', 'clockValue', 'timeRow', 'seconds', 'mime'];
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
    }

    async refresh() {
        const response = await fetch(this.viewUrlValue, { headers: { Accept: 'application/json' } });

        if (response.ok) {
            this.#render(await response.json());
        }
    }

    async pickCategory() {
        await this.#send('pick_category', { category: this.categorySelectTarget.value });
    }

    async act(event) {
        const action = event.currentTarget.dataset.kalamburyAction;
        const payload = 'start' === action ? { seconds: Number(this.secondsTarget.value) } : {};

        await this.#send(action, payload);
    }

    async guessedBy(event) {
        await this.#send('guessed', { teamId: event.currentTarget.dataset.teamId });
    }

    #onServerEvent(event) {
        if ('round_over' === event.type) {
            this.#flash('guessed' === event.reason
                ? `${event.teamName} odgaduje!`
                : 'Czas minął');

            // Przy wyczerpanym czasie nic tu nie gramy: rundę kończy zegar,
            // a ten zdążył już zatrąbić końcem odliczania.
            if ('guessed' === event.reason) {
                sfx.play('correct');
                sfx.play('applause', { delay: 0.55 });
            }
        }

        if ('card_drawn' === event.type) {
            sfx.play('whoosh');
        }

        if ('hint' === event.type) {
            sfx.play('sparkle');
        }

        if (['phase', 'timer', 'card_drawn', 'card_skipped', 'round_over', 'turn',
            'hint', 'category_picked', 'team_joined', 'reset'].includes(event.type)) {
            if ('team_joined' === event.type) {
                this.teams.set(event.team.id, event.team.name);
            }

            this.refresh();
        }
    }

    #render(view) {
        this.categoryPickerTarget.hidden = Boolean(view.category);

        if (!view.category) {
            this.#fillCategories(view.categories ?? []);
        }

        this.progressTarget.textContent = view.category
            ? `${view.categoryName} — karta ${view.cardNumber} z ${view.cardTotal}`
            : '';

        this.#renderBoard(view.mask);
        this.turnTarget.textContent = view.turn ? `Pokazuje: ${this.#teamName(view.turn)}` : '';

        // Hasło dopiero po rundzie - w trakcie patrzą na nie zgadujący.
        this.answerTarget.textContent = ['guessed', 'timeout'].includes(view.state)
            ? (view.word ?? '')
            : '';

        this.timeRowTarget.hidden = 'drawn' !== view.state;

        // Ludzik macha tylko w trakcie pokazywania - poza rundą stoi.
        if (this.hasMimeTarget) {
            this.mimeTarget.classList.toggle('acting', 'acting' === view.state);
        }
        this.#syncClock(view);
        this.#renderControls(view);
    }

    /**
     * Kształt hasła: ile wyrazów i ile znaków. Kafelek ma dwie strony, tak jak
     * w kole - zakrytą i literę na odwrocie. W kalamburach odwraca się tylko
     * jedna, po kole ratunkowym, i wtedy ten obrót ma być widoczny.
     *
     * Dlatego plansza przebudowuje się wyłącznie przy nowym haśle: pełna
     * przebudowa po podpowiedzi wstawiłaby literę bez ruchu.
     */
    #renderBoard(mask) {
        if (!mask) {
            this.boardTarget.replaceChildren();
            this.mask = '';

            return;
        }

        if (this.mask?.length !== mask.length) {
            this.mask = mask;
            this.boardTarget.replaceChildren(...[...mask].map((char) => this.#cell(char)));

            return;
        }

        [...mask].forEach((char, index) => {
            if (char === this.mask[index]) {
                return;
            }

            const inner = this.boardTarget.children[index]?.querySelector('.cell-inner');

            if (inner) {
                inner.querySelector('.cell-front').textContent = char;
                inner.classList.add('revealed');
            }
        });

        this.mask = mask;
    }

    #cell(char) {
        const cell = document.createElement('span');
        cell.className = 'puzzle-cell';

        if (' ' === char) {
            cell.classList.add('puzzle-space');

            return cell;
        }

        const inner = document.createElement('span');
        inner.className = 'cell-inner';

        const back = document.createElement('span');
        back.className = 'cell-face cell-back';

        const front = document.createElement('span');
        front.className = 'cell-face cell-front';

        if ('_' !== char) {
            front.textContent = char;
            inner.classList.add('revealed');
        }

        inner.append(back, front);
        cell.append(inner);

        return cell;
    }

    #syncClock(view) {
        clearInterval(this.ticker);

        if (!view.deadline || 'acting' !== view.state) {
            this.clockTarget.hidden = true;

            return;
        }

        // Różnica między zegarem serwera a naszym; bez niej licznik na
        // telewizorze z przestawioną datą pokazywałby bzdury.
        this.offset = view.serverNow - Date.now();
        this.clockTarget.hidden = false;

        const tick = () => {
            const left = Math.max(0, view.deadline - (Date.now() + this.offset));
            this.clockValueTarget.textContent = Math.ceil(left / 1000);
            this.clockTarget.dataset.state = left < 10_000 ? 'urgent' : 'running';

            if (0 === left) {
                clearInterval(this.ticker);
                // O upływie czasu i tak rozstrzyga serwer - my tylko meldujemy.
                this.#send('timeout');
            }
        };

        tick();
        this.ticker = setInterval(tick, 250);
    }

    #renderControls(view) {
        const buttons = {
            idle: view.category ? [['next_card', 'Losuj hasło', 'primary']] : [],
            drawn: [['start', '▶ Start', 'primary'], ['skip', 'Inne hasło', '']],
            acting: [],
            guessed: [['next_round', 'Następna drużyna', 'primary']],
            timeout: [['next_round', 'Następna drużyna', 'primary']],
        }[view.state] ?? [];

        const nodes = buttons.map(([action, label, variant]) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `btn btn-lg${'primary' === variant ? ' btn-primary' : ''}`;
            button.textContent = label;
            button.dataset.kalamburyAction = action;
            button.dataset.action = 'kalambury-host#act';

            return button;
        });

        // Koło ratunkowe: jedna litera na rundę, więc przycisk znika po użyciu.
        if (['drawn', 'acting'].includes(view.state) && !view.hintUsed) {
            const lifeline = document.createElement('button');
            lifeline.type = 'button';
            lifeline.className = 'btn btn-lg btn-lifeline';
            lifeline.textContent = '🛟 Koło ratunkowe';
            lifeline.dataset.kalamburyAction = 'hint';
            lifeline.dataset.action = 'kalambury-host#act';
            nodes.push(lifeline);
        }

        // W trakcie pokazywania host klika drużynę, która odgadła - punkt
        // dostają zgadujący i pokazujący, więc wskazanie musi być jawne.
        if ('acting' === view.state) {
            (view.teams ?? [])
                .filter((team) => team.id !== view.turn)
                .forEach((team) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'btn btn-lg';
                    button.textContent = `✓ ${team.name}`;
                    button.dataset.teamId = team.id;
                    button.dataset.action = 'kalambury-host#guessedBy';
                    nodes.push(button);
                });
        }

        this.controlsTarget.replaceChildren(...nodes);

        this.hintTarget.textContent = {
            idle: view.category ? 'Wylosuj hasło dla pokazującej drużyny.' : 'Wybierz kategorię.',
            drawn: 'Hasło poszło na telefon aktora. Ustaw czas i startuj.',
            acting: view.hintUsed
                ? 'Kliknij drużynę, która odgadła.'
                : 'Kliknij drużynę, która odgadła. Utknęli? Koło ratunkowe odsłoni pierwszą literę.',
            guessed: 'Odgadnięte.',
            timeout: 'Czas minął — nikt nie odgadł.',
        }[view.state] ?? '';
    }

    #fillCategories(categories) {
        this.categorySelectTarget.replaceChildren(...categories.map((category) => {
            const option = document.createElement('option');
            option.value = category.slug;
            option.textContent = `${category.name} (${category.items})`;

            return option;
        }));
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
