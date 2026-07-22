import { Controller } from '@hotwired/stimulus';

/**
 * Sterownik kalambur na telefonie.
 *
 * Hasło widzi wyłącznie aktor i przychodzi ono pod-topikiem jego drużyny -
 * nigdy rozgłoszeniem. Reszta drużyn dostaje tylko licznik.
 */
export default class extends Controller {
    static targets = ['wordCard', 'word', 'clock', 'clockValue', 'note'];
    static values = { viewUrl: String, state: String, actor: Boolean };

    connect() {
        this.offset = 0;

        this.onEvent = (event) => this.#handle(event.detail);
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

    #handle(event) {
        // Słowo dla aktora leci osobnym eventem na jego pod-topic.
        if ('your_word' === event.type) {
            this.wordTarget.textContent = event.word;
        }

        if (['phase', 'timer', 'card_drawn', 'card_skipped', 'round_over', 'turn',
            'category_picked', 'reset'].includes(event.type)) {
            this.refresh();
        }
    }

    #render(view) {
        this.stateValue = view.state;
        this.actorValue = Boolean(view.iAmActor);

        if (view.word) {
            this.wordTarget.textContent = view.word;
        }

        this.wordCardTarget.hidden = !this.actorValue || !view.word
            || !['drawn', 'acting'].includes(view.state);

        this.#syncClock(view);

        this.noteTarget.textContent = this.actorValue
            ? { drawn: 'Za chwilę start — przygotuj się.', acting: 'Pokazuj!' }[view.state] ?? 'Twoja kolej pokazywać.'
            : { acting: 'Zgaduj na głos!', drawn: 'Zaraz zacznie się pokaz.' }[view.state] ?? 'Czekaj na swoją kolej.';
    }

    #syncClock(view) {
        clearInterval(this.ticker);

        if (!view.deadline || 'acting' !== view.state) {
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
            }
        };

        tick();
        this.ticker = setInterval(tick, 250);
    }
}
