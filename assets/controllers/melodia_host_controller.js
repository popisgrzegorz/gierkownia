import { Controller } from '@hotwired/stimulus';
import { sfx } from '../audio/sfx.js';
import { popIn, celebrate, shake } from '../anim.js';

/**
 * Panel "Jaka to melodia" na ekranie hosta.
 *
 * Stan gry trzyma serwer; ten kontroler wyłącznie go pokazuje i odtwarza dźwięk.
 * Po każdej zmianie fazy dociąga widok osobnym żądaniem, bo niesie on tytuł
 * utworu - a tego nie wolno rozesłać broadcastem do telefonów.
 */
export default class extends Controller {
    static targets = ['audio', 'vinyl', 'categoryPicker', 'categoryList', 'progress', 'answer', 'banner', 'controls', 'hint'];
    static values = { actionUrl: String, viewUrl: String, mediaUrl: String };

    connect() {
        this.onEvent = (event) => this.#onServerEvent(event.detail);
        window.addEventListener('gierki:event', this.onEvent);

        this.refresh();
    }

    disconnect() {
        window.removeEventListener('gierki:event', this.onEvent);
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
        const button = event.currentTarget;

        // Bez blokady podwójny klik wysyłał tę samą akcję dwukrotnie - tu
        // dodatkowo groziło podwójnym "Losuj utwór" (pomijanie utworu z puli).
        if (button.disabled) {
            return;
        }

        const action = button.dataset.melodiaAction;
        button.disabled = true;

        try {
            // "Następny utwór" to dwie rzeczy naraz: zamknięcie rundy i losowanie.
            // Serwerowe akcje zostają atomowe (finish kończy rundę, next_track
            // losuje), ale host nie ma powodu klikać dwa razy - a wcześniej ten
            // przycisk kończył rundę i zostawiał na ekranie ten sam utwór.
            if ('finish_next' === action) {
                await this.#send('finish');
                await this.#send('next_track');

                return;
            }

            await this.#send(action);
        } finally {
            button.disabled = false;
        }
    }

    #onServerEvent(event) {
        // Kryterium akceptacji ze spec 16: pierwsze naciśnięcie zatrzymuje
        // melodię. Reagujemy na event, a nie na własne żądanie, bo o zwycięzcy
        // rozstrzyga serwer i to jego wiadomość jest tu jedyną prawdą.
        if ('buzz' === event.type) {
            this.audioTarget.pause();
        }

        if ('judged' === event.type) {
            // Oklaski wchodzą po dzwonku, a nie razem z nim - inaczej pierwsza
            // nuta ginie w szumie i trafienie brzmi jak pomyłka.
            sfx.play(event.correct ? 'correct' : 'wrong');

            if (event.correct) {
                sfx.play('applause', { delay: 0.55 });
            }
        }

        if ('track_revealed' === event.type) {
            sfx.play('reveal');
        }

        if (['phase', 'buzz', 'judged', 'track_ready', 'category_picked', 'reset', 'resync'].includes(event.type)) {
            const done = this.refresh();

            // Baner „ma głos!" wskakuje z impetem, trafienie świętuje, pudło potrząsa.
            if ('buzz' === event.type) {
                done.then(() => popIn(this.bannerTarget, { rotation: -6 }));
            } else if ('judged' === event.type) {
                done.then(() => (event.correct ? celebrate(this.vinylTarget) : shake(this.vinylTarget)));
            }
        }
    }

    #render(view) {
        this.view = view;

        const picked = view.picked ?? [];
        const hasCategory = 0 < picked.length;
        this.categoryPickerTarget.hidden = hasCategory;

        if (!hasCategory) {
            this.#fillCategories(view.categories ?? []);
        }

        this.progressTarget.textContent = hasCategory
            ? `${view.categoryName} — utwór ${view.trackNumber} z ${view.trackTotal}`
            : '';

        // Tytuł wyłącznie po odsłonięciu (finish/cancel), nigdy przy ocenie.
        // Przy 'buzzed' i 'judging' pokazywaliśmy go „dla hosta" - ale hostem
        // jest telewizor, na który patrzy cały pokój. Po złej odpowiedzi utwór
        // wraca do gry przejściem 'rearm', więc zdradzony tytuł psuł zagadkę
        // wszystkim pozostałym drużynom.
        this.answerTarget.textContent = view.revealed
            ? `${view.revealed.artist} — ${view.revealed.title}`
            : '';

        this.#renderBanner(view);
        this.#renderControls(view);
        this.#syncAudio(view);
    }

    #renderBanner(view) {
        const buzz = view.buzz;
        this.bannerTarget.hidden = !buzz;

        if (buzz) {
            this.bannerTarget.textContent = `${buzz.teamName} ma głos!`;
            this.bannerTarget.style.setProperty('--team-color', buzz.color);
        }
    }

    /** Utwór da się jeszcze zagrać tylko wtedy, gdy nikt nie zna tytułu. */
    #playable(view) {
        return Boolean(view.track) && !view.revealed;
    }

    #idleHint(view) {
        if (0 === (view.picked ?? []).length) {
            return 'Zaznacz kategorie i naciśnij „Graj”.';
        }

        return this.#playable(view)
            ? 'Wylosuj utwór i puść go gościom.'
            : 'Runda zamknięta — losuj kolejny utwór.';
    }

    #renderControls(view) {
        const buttons = {
            // Utwór już odsłonięty (runda zamknięta) nie nadaje się do grania
            // drugi raz - wszyscy znają odpowiedź. Jedyne sensowne wyjście
            // to kolejne nagranie.
            idle: this.#playable(view)
                ? [['arm', '▶ Graj', 'primary'], ['next_track', 'Inny utwór', '']]
                : [['next_track', view.track ? 'Następny utwór' : 'Losuj utwór', 'primary']],
            armed: [['cancel', '⏹ Nikt nie zgadł', '']],
            buzzed: [['judge_ok', '✓ Dobrze', 'primary'], ['judge_no', '✗ Źle', '']],
            judging: 'wrong' === view.lastResult
                ? [['rearm', '▶ Graj dalej', 'primary'], ['finish_next', 'Następny utwór', '']]
                : [['finish_next', 'Następny utwór', 'primary']],
        }[view.state] ?? [];

        this.controlsTarget.replaceChildren(...buttons.map(([action, label, variant]) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `btn btn-lg${'primary' === variant ? ' btn-primary' : ''}`;
            button.textContent = label;
            button.dataset.melodiaAction = action;
            button.dataset.action = 'melodia-host#act';

            return button;
        }));

        this.hintTarget.textContent = {
            idle: this.#idleHint(view),
            armed: 'Buzzery aktywne — pierwszy telefon zatrzyma muzykę.',
            buzzed: 'Wysłuchaj odpowiedzi i oceń ją.',
            judging: 'wrong' === view.lastResult ? 'Możesz puścić utwór dalej dla pozostałych.' : '',
        }[view.state] ?? '';
    }

    /**
     * Kafelki kategorii do zaznaczania. Przebudowa tylko wtedy, gdy zmienił się
     * zestaw kategorii - inaczej odświeżenie po dołączeniu drużyny kasowałoby
     * zaznaczenia w trakcie wybierania.
     */
    #fillCategories(categories) {
        const slugs = categories.map((category) => category.slug).join(',');

        if (this.categoryListTarget.dataset.slugs === slugs) {
            return;
        }

        this.categoryListTarget.dataset.slugs = slugs;
        this.categoryListTarget.replaceChildren(...categories.map((category) => {
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'cat-chip';
            chip.dataset.slug = category.slug;
            chip.dataset.action = 'melodia-host#toggleCategory';
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

    #syncAudio(view) {
        if (!view.track) {
            this.audioTarget.removeAttribute('src');
            this.#spinVinyl(false);

            return;
        }

        // Adres jest budowany z id, nigdy z nazwy pliku - ta zdradziłaby tytuł
        // każdemu, kto zajrzy do zakładki Sieć.
        const src = this.mediaUrlValue.replace(/\/0$/, `/${view.track.id}`);

        if (!this.audioTarget.src.endsWith(src)) {
            this.audioTarget.src = src;
            this.audioTarget.currentTime = view.track.startAt ?? 0;
        }

        if ('armed' === view.state) {
            // Wznowienie po złej odpowiedzi ma iść dalej, a nie od początku -
            // stąd play() bez zerowania pozycji.
            this.audioTarget.play().catch(() => {
                this.hintTarget.textContent = 'Przeglądarka zablokowała dźwięk — kliknij gdziekolwiek na ekranie.';
            });
        } else {
            this.audioTarget.pause();
        }

        // Płyta kręci się dokładnie wtedy, gdy leci dźwięk. To jedyny sygnał,
        // że muzyka gra - głośnik stoi obok telewizora, a nie w nim.
        this.#spinVinyl('armed' === view.state);
    }

    #spinVinyl(playing) {
        if (this.hasVinylTarget) {
            this.vinylTarget.classList.toggle('playing', playing);
        }
    }

    async #send(action, payload = {}) {
        const isJudge = action.startsWith('judge_');

        const response = await fetch(this.actionUrlValue, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({
                action: isJudge ? 'judge' : action,
                payload: isJudge ? { correct: 'judge_ok' === action } : payload,
            }),
        });

        if (!response.ok) {
            const problem = await response.json().catch(() => ({}));
            this.hintTarget.textContent = problem.detail ?? 'Nie udało się wykonać akcji.';

            return;
        }

        this.refresh();
    }
}
