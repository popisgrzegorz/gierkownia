import { Controller } from '@hotwired/stimulus';

/** Polski alfabet - "Ł" nie odsłania "L", więc litery diakrytyczne mają własne klawisze. */
const ALPHABET = [...'AĄBCĆDEĘFGHIJKLŁMNŃOÓPRSŚTUWYZŹŻ'];

/**
 * Sterownik koła fortuny na telefonie.
 *
 * Pokazuje planszę wszystkim, ale przyciski wyłącznie drużynie, do której
 * należy tura. Serwer i tak odrzuci akcję nie w swojej kolejce - chodzi o to,
 * żeby nikt nie klikał na próżno i było widać, na kogo czekamy.
 */
export default class extends Controller {
    static targets = ['board', 'hint', 'bank', 'note', 'actions', 'spin', 'letters', 'answer'];

    /** Po tylu milisekundach trzymania zamach jest maksymalny. */
    static FULL_POWER_MS = 1600;
    static values = { actionUrl: String, teamId: String, state: String, myTurn: Boolean };

    connect() {
        this.guessed = new Set();

        this.#buildKeyboard();

        this.onEvent = (event) => this.#handle(event.detail);
        window.addEventListener('gierki:event', this.onEvent);

        this.#render();
    }

    disconnect() {
        window.removeEventListener('gierki:event', this.onEvent);
    }

    /**
     * Zamach: im dłużej trzymasz przycisk, tym więcej obrotów na telewizorze.
     *
     * Wynik i tak zapada na serwerze - siła zmienia wyłącznie animację.
     * Gdyby zmieniała sektor, telefon decydowałby o wygranej.
     */
    charge() {
        if (this.charging) {
            return;
        }

        this.charging = performance.now();
        this.spinTarget.classList.add('charging');

        const grow = () => {
            if (!this.charging) {
                return;
            }

            this.spinTarget.style.setProperty('--power', this.#power() / 100);
            this.chargeFrame = requestAnimationFrame(grow);
        };

        grow();
    }

    async release() {
        if (!this.charging) {
            return;
        }

        const power = this.#power();

        cancelAnimationFrame(this.chargeFrame);
        this.charging = null;
        this.spinTarget.classList.remove('charging');
        this.spinTarget.style.removeProperty('--power');

        await this.#send('spin', { power });
    }

    /** Krótkie stuknięcie to nie zero - koło ma się kręcić także wtedy. */
    #power() {
        const held = performance.now() - (this.charging ?? performance.now());

        return Math.round(15 + 85 * Math.min(1, held / this.constructor.FULL_POWER_MS));
    }

    async letter(event) {
        const letter = event.currentTarget.dataset.letter;

        // Blokujemy klawisz od razu: serwer i tak odrzuci powtórkę, ale gracz
        // ma widzieć, że litera poszła, zanim wróci odpowiedź.
        this.guessed.add(letter);
        this.#render();

        await this.#send('letter', { letter });
    }

    async solve(event) {
        event.preventDefault();

        const phrase = this.answerTarget.value.trim();

        if ('' === phrase) {
            return;
        }

        this.answerTarget.value = '';
        await this.#send('solve', { phrase });
    }

    #handle(event) {
        switch (event.type) {
            case 'puzzle_ready':
                this.guessed.clear();
                this.boardTarget.textContent = event.mask;
                this.hintTarget.textContent = [event.category, event.hint].filter(Boolean).join(' • ');
                this.myTurnValue = event.turn === this.teamIdValue;
                this.stateValue = 'next_team';
                break;

            case 'letter_revealed':
                this.guessed.add(event.letter);
                this.boardTarget.textContent = event.mask;
                break;

            case 'puzzle_solved':
                this.boardTarget.textContent = event.phrase;
                this.myTurnValue = false;
                break;

            case 'turn':
                this.myTurnValue = event.teamId === this.teamIdValue;
                break;

            case 'phase':
                this.stateValue = event.state;
                break;

            case 'reset':
                this.guessed.clear();
                this.myTurnValue = false;
                break;
        }

        // Bank aktualizujemy z każdego eventu, który go niesie - wcześniej
        // robiła to wyłącznie trafiona litera, więc bankrut, stop i rozwiązane
        // hasło zostawiały na telefonie nieaktualną kwotę już do końca gry.
        // Serwer przysyła stan wszystkich drużyn, więc nie ma czego doliczać.
        if (event.banks) {
            this.bankTarget.textContent = String(event.banks[this.teamIdValue] ?? 0);
        }

        this.#render();
    }

    #render() {
        const canSpin = this.myTurnValue && ['idle', 'next_team'].includes(this.stateValue);
        const canPickLetter = this.myTurnValue && 'pick_letter' === this.stateValue;

        this.actionsTarget.hidden = !this.myTurnValue;
        this.spinTarget.hidden = !canSpin;
        this.lettersTarget.hidden = !canPickLetter;

        this.lettersTarget.querySelectorAll('button').forEach((button) => {
            button.disabled = this.guessed.has(button.dataset.letter);
        });

        this.noteTarget.textContent = this.myTurnValue
            ? { spinning: 'Koło się kręci…', pick_letter: 'Podaj literę!', next_team: 'Twoja kolej — kręć!' }[this.stateValue] ?? 'Twoja kolej'
            : 'Teraz gra inna drużyna.';
    }

    #buildKeyboard() {
        this.lettersTarget.replaceChildren(...ALPHABET.map((letter) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'letter-key';
            button.textContent = letter;
            button.dataset.letter = letter;
            button.dataset.action = 'kolo-player#letter';

            return button;
        }));
    }

    async #send(action, payload = {}) {
        const response = await fetch(this.actionUrlValue, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ action, payload }),
        });

        if (!response.ok) {
            const problem = await response.json().catch(() => ({}));
            this.noteTarget.textContent = problem.detail ?? 'Nie udało się.';
        }
    }
}
