import { Controller } from '@hotwired/stimulus';

/* Obrys kasety ma pathLength="100", więc odliczanie liczy się w procentach
   długości - bez wyliczania obwodu zaokrąglonego prostokąta. */
const RING = 100;

const LETTERS = ['A', 'B', 'C', 'D'];

/**
 * Test melodii na ekranie hosta.
 *
 * Poprawna odpowiedź przychodzi z serwera dopiero z wynikami - do tego czasu
 * ten kontroler jej nie zna i nie ma jak zdradzić. To celowe: na telewizor
 * patrzą ci sami ludzie, którzy odpowiadają na telefonach.
 */
export default class extends Controller {
    static targets = ['audio', 'disc', 'ring', 'clockValue', 'clockLabel', 'eq', 'categoryPicker',
        'categoryList', 'progress', 'options', 'message', 'who', 'timeRow', 'seconds',
        'controls', 'hint'];

    static values = { actionUrl: String, viewUrl: String, mediaUrl: String };

    connect() {
        this.offset = 0;

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

    /** Zaznaczenie kategorii - wybór potwierdza dopiero przycisk. */
    toggleCategory(event) {
        const chip = event.currentTarget;
        chip.setAttribute('aria-pressed', 'true' === chip.getAttribute('aria-pressed') ? 'false' : 'true');
        chip.classList.toggle('on');
    }

    async pickCategory() {
        const categories = [...this.categoryListTarget.querySelectorAll('[aria-pressed="true"]')]
            .map((chip) => chip.dataset.slug);

        if (0 === categories.length) {
            this.hintTarget.textContent = 'Zaznacz przynajmniej jedną kategorię.';

            return;
        }

        await this.#send('pick_category', { categories });
    }

    async act(event) {
        const action = event.currentTarget.dataset.quizAction;
        const payload = 'start' === action ? { seconds: Number(this.secondsTarget.value) } : {};

        await this.#send(action, payload);
    }

    #onServerEvent(event) {
        if (['phase', 'timer', 'question_ready', 'answered', 'results', 'again',
            'round_reset', 'category_picked', 'team_joined', 'reset'].includes(event.type)) {
            this.refresh();
        }
    }

    #render(view) {
        const picked = view.picked ?? [];

        // Wybór kategorii zostaje dostępny w idle - tam ląduje pokój, gdy
        // pula się wyczerpie, a bez tej listy host nie miałby jak ruszyć dalej.
        this.categoryPickerTarget.hidden = 0 < picked.length && 'idle' !== view.state;

        if (!this.categoryPickerTarget.hidden) {
            this.#fillCategories(view.categories ?? [], picked);
        }

        this.progressTarget.textContent = 0 < picked.length
            ? `${view.categoryName} — utwór ${view.trackNumber} z ${view.trackTotal}`
                + (1 < view.attempt ? ` · próba ${view.attempt}` : '')
            : '';

        this.timeRowTarget.hidden = 'ready' !== view.state;

        this.#renderOptions(view);
        this.#renderWho(view);
        this.#renderMessage(view);
        this.#syncAudio(view);
        this.#syncClock(view);
        this.#renderControls(view);
    }

    #renderOptions(view) {
        const results = 'results' === view.state;
        // Kto co wybrał, wiadomo dopiero po rundzie - wcześniej serwer tego
        // nie przysyła, więc tabela jest pusta i nie ma czego podejrzeć.
        const picks = Object.values(view.answers ?? {});

        // Cztery odpowiedzi wchodzą jedna po drugiej, a wyniki odsłaniają się
        // tą samą kaskadą. Wpisanie ich naraz gubi moment, w którym cała sala
        // czyta karty - a to on decyduje, czy runda jest emocjonująca.
        const stamp = `${(view.options ?? []).join('|')}#${results ? view.correct : ''}`;
        const fresh = this.optionsTarget.dataset.stamp !== stamp;
        this.optionsTarget.dataset.stamp = stamp;

        this.optionsTarget.replaceChildren(...(view.options ?? []).map((title, index) => {
            const card = document.createElement('div');
            card.className = 'quiz-option';

            if (fresh) {
                card.classList.add('entering');
                card.style.setProperty('--enter-delay', `${90 * index}ms`);
            }

            if (results) {
                if (index === view.correct) {
                    card.classList.add('good');
                } else if (picks.includes(index)) {
                    card.classList.add('bad');
                } else {
                    card.classList.add('dim');
                }
            }

            const letter = document.createElement('span');
            letter.className = 'quiz-letter';
            letter.textContent = LETTERS[index] ?? '?';

            const text = document.createElement('span');
            text.className = 'quiz-title';
            text.textContent = title;

            card.append(letter, text);

            // Ile drużyn wskazało tę odpowiedź - widać to dopiero z wynikami.
            const count = picks.filter((pick) => pick === index).length;

            if (results && 0 < count) {
                const badge = document.createElement('span');
                badge.className = 'quiz-count-badge';
                badge.textContent = count;
                card.append(badge);
            }

            return card;
        }));
    }

    /** Plakietki drużyn: kto już odpowiedział. Nigdy CO odpowiedział. */
    #renderWho(view) {
        const answered = new Set(view.answeredBy ?? []);
        const winners = new Set(view.winners ?? []);
        const results = 'results' === view.state;

        this.whoTarget.replaceChildren(...(view.teams ?? []).map((team) => {
            const chip = document.createElement('span');
            chip.className = 'quiz-chip';
            chip.style.setProperty('--team-color', team.color);

            const mark = document.createElement('span');
            mark.className = 'quiz-chip-mark';

            const name = document.createElement('span');
            name.textContent = team.name;

            chip.append(mark, name);

            if (results && winners.has(team.id)) {
                chip.classList.add('won');
                mark.textContent = '✓';

                const points = document.createElement('span');
                points.className = 'quiz-chip-points';
                points.textContent = `+${view.points}`;
                chip.append(points);
            } else if (results) {
                // Po rundzie krzyżyk dostaje i ten, kto wskazał źle, i ten,
                // kto nie zdążył - z sali różnica nie ma już znaczenia.
                chip.classList.add('missed');
                mark.textContent = '✕';
            } else if (answered.has(team.id)) {
                chip.classList.add('answered');
                mark.textContent = '✓';
            } else {
                // W trakcie rundy widać tylko "jeszcze nie" - co wybrali,
                // zostaje tajemnicą do końca.
                mark.textContent = '•';
            }

            return chip;
        }));
    }

    #renderMessage(view) {
        if ('results' !== view.state) {
            this.messageTarget.textContent = '';
            this.messageTarget.className = 'quiz-message';

            return;
        }

        const winners = view.winners ?? [];
        const names = (view.teams ?? [])
            .filter((team) => winners.includes(team.id))
            .map((team) => team.name);

        this.messageTarget.textContent = 0 === names.length
            ? `Nikt nie trafił — to „${view.revealed}”`
            : `${names.join(', ')} — dobrze! +${view.points}`;

        this.messageTarget.className = `quiz-message ${0 === names.length ? 'bad' : 'good'}`;
    }

    #syncAudio(view) {
        if (!view.track) {
            this.audioTarget.removeAttribute('src');
            this.discTarget.classList.remove('playing');

            return;
        }

        const src = this.mediaUrlValue.replace(/\/0$/, `/${view.track.id}`);

        if (!this.audioTarget.src.endsWith(src)) {
            this.audioTarget.src = src;
            this.audioTarget.currentTime = view.track.startAt ?? 0;
        }

        if ('answering' === view.state) {
            this.audioTarget.play().catch(() => {
                this.hintTarget.textContent = 'Przeglądarka zablokowała dźwięk — kliknij gdziekolwiek na ekranie.';
            });
        } else {
            this.audioTarget.pause();
        }

        this.discTarget.classList.toggle('playing', 'answering' === view.state);
    }

    #syncClock(view) {
        clearInterval(this.ticker);

        if (!view.deadline || 'answering' !== view.state) {
            this.clockValueTarget.textContent = 'results' === view.state ? '–' : '--';
            this.clockLabelTarget.textContent = 'results' === view.state ? 'KONIEC' : 'SEKUND';
            this.ringTarget.style.strokeDashoffset = RING;

            return;
        }

        // Zegar telewizora bywa przestawiony - liczymy od różnicy wobec serwera.
        this.offset = view.serverNow - Date.now();
        this.clockLabelTarget.textContent = 'SEKUND';

        const total = (view.seconds ?? 30) * 1000;

        const tick = () => {
            const left = Math.max(0, view.deadline - (Date.now() + this.offset));
            this.clockValueTarget.textContent = Math.ceil(left / 1000);
            this.ringTarget.style.strokeDashoffset = RING * (1 - left / total);
            this.discTarget.dataset.state = left < 10_000 ? 'urgent' : 'running';

            if (0 === left) {
                clearInterval(this.ticker);
                // O upływie czasu i tak rozstrzyga serwer - my tylko meldujemy.
                this.#send('finish');
            }
        };

        tick();
        this.ticker = setInterval(tick, 200);
    }

    #renderControls(view) {
        const nobodyWon = 0 === (view.winners ?? []).length;
        // Pokój wraca do idle także wtedy, gdy „Następny utwór" nie miał już
        // czego wylosować - wtedy przycisk losowania byłby ślepy.
        const empty = view.trackTotal <= view.trackNumber;

        const buttons = {
            idle: 0 < (view.picked ?? []).length && !empty ? [['next_track', 'Losuj utwór', 'primary']] : [],
            ready: [['start', '▶ Start', 'primary'], ['next_track', 'Inny utwór', '']],
            answering: [['finish', 'Kończ rundę', '']],
            results: nobodyWon
                ? [['again', '↻ Jeszcze raz', 'primary'], ['next_round', 'Następny utwór', '']]
                : [['next_round', 'Następny utwór', 'primary']],
        }[view.state] ?? [];

        this.controlsTarget.replaceChildren(...buttons.map(([action, label, variant]) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `btn btn-lg${'primary' === variant ? ' btn-primary' : ''}`;
            button.textContent = label;
            button.dataset.quizAction = action;
            button.dataset.action = 'quiz-host#act';

            return button;
        }));

        const idleHint = empty
            ? 'Kategoria się skończyła — wybierz inną z listy.'
            : 'Wylosuj utwór - odpowiedzi ułożą się same.';

        this.hintTarget.textContent = {
            idle: 0 < (view.picked ?? []).length ? idleHint : 'Zaznacz kategorie i naciśnij „Graj”.',
            ready: 'Odpowiedzi na ekranie. Startuj, gdy wszyscy patrzą.',
            answering: 'Drużyny wybierają na telefonach. Runda kończy się sama, gdy odpowiedzą wszyscy.',
            results: nobodyWon ? 'Nikt nie trafił — możesz puścić ten sam utwór jeszcze raz.' : '',
        }[view.state] ?? '';
    }

    /**
     * Kafelki kategorii do zaznaczania. Przebudowa tylko wtedy, gdy zmienił się
     * zestaw kategorii - inaczej odświeżenie po dołączeniu drużyny kasowałoby
     * zaznaczenia w trakcie wybierania.
     */
    #fillCategories(categories, picked) {
        const slugs = categories.map((category) => category.slug).join(',');

        if (this.categoryListTarget.dataset.slugs !== slugs) {
            this.categoryListTarget.dataset.slugs = slugs;
            this.categoryListTarget.replaceChildren(...categories.map((category) => {
                const chip = document.createElement('button');
                chip.type = 'button';
                chip.className = 'cat-chip';
                chip.dataset.slug = category.slug;
                chip.dataset.action = 'quiz-host#toggleCategory';
                chip.setAttribute('aria-pressed', 'false');

                const name = document.createElement('span');
                name.textContent = category.name;

                const count = document.createElement('span');
                count.className = 'cat-chip-count';
                count.textContent = category.items;

                chip.append(name, count);

                return chip;
            }));
        }

        // Po wyborze kafelki pokazują, co gra teraz - host widzi to samo, co gracze.
        this.categoryListTarget.querySelectorAll('.cat-chip').forEach((chip) => {
            const on = picked.includes(chip.dataset.slug);

            if (0 < picked.length) {
                chip.setAttribute('aria-pressed', on ? 'true' : 'false');
                chip.classList.toggle('on', on);
            }
        });
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
