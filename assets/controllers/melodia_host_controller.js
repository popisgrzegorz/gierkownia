import { Controller } from '@hotwired/stimulus';

/**
 * Panel "Jaka to melodia" na ekranie hosta.
 *
 * Stan gry trzyma serwer; ten kontroler wyłącznie go pokazuje i odtwarza dźwięk.
 * Po każdej zmianie fazy dociąga widok osobnym żądaniem, bo niesie on tytuł
 * utworu - a tego nie wolno rozesłać broadcastem do telefonów.
 */
export default class extends Controller {
    static targets = ['audio', 'categoryPicker', 'categorySelect', 'progress', 'answer', 'banner', 'controls', 'hint'];
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
        const response = await fetch(this.viewUrlValue, { headers: { Accept: 'application/json' } });

        if (response.ok) {
            this.#render(await response.json());
        }
    }

    async pickCategory() {
        await this.#send('pick_category', { category: this.categorySelectTarget.value });
    }

    async act(event) {
        await this.#send(event.currentTarget.dataset.melodiaAction);
    }

    #onServerEvent(event) {
        // Kryterium akceptacji ze spec 16: pierwsze naciśnięcie zatrzymuje
        // melodię. Reagujemy na event, a nie na własne żądanie, bo o zwycięzcy
        // rozstrzyga serwer i to jego wiadomość jest tu jedyną prawdą.
        if ('buzz' === event.type) {
            this.audioTarget.pause();
        }

        if (['phase', 'buzz', 'judged', 'track_ready', 'category_picked', 'reset'].includes(event.type)) {
            this.refresh();
        }
    }

    #render(view) {
        this.view = view;

        const hasCategory = Boolean(view.category);
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

    #renderControls(view) {
        const buttons = {
            idle: view.track
                ? [['arm', '▶ Graj', 'primary'], ['next_track', 'Inny utwór', '']]
                : [['next_track', 'Losuj utwór', 'primary']],
            armed: [['cancel', '⏹ Nikt nie zgadł', '']],
            buzzed: [['judge_ok', '✓ Dobrze', 'primary'], ['judge_no', '✗ Źle', '']],
            judging: 'wrong' === view.lastResult
                ? [['rearm', '▶ Graj dalej', 'primary'], ['finish', 'Koniec rundy', '']]
                : [['finish', 'Następny utwór', 'primary']],
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
            idle: view.category ? 'Wylosuj utwór i puść go gościom.' : 'Wybierz kategorię, żeby zacząć.',
            armed: 'Buzzery aktywne — pierwszy telefon zatrzyma muzykę.',
            buzzed: 'Wysłuchaj odpowiedzi i oceń ją.',
            judging: 'wrong' === view.lastResult ? 'Możesz puścić utwór dalej dla pozostałych.' : '',
        }[view.state] ?? '';
    }

    #fillCategories(categories) {
        if (this.categorySelectTarget.options.length === categories.length) {
            return;
        }

        this.categorySelectTarget.replaceChildren(...categories.map((category) => {
            const option = document.createElement('option');
            option.value = category.slug;
            option.textContent = `${category.name} (${category.items})`;

            return option;
        }));
    }

    #syncAudio(view) {
        if (!view.track) {
            this.audioTarget.removeAttribute('src');

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
