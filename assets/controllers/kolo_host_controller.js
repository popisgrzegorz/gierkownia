import { Controller } from '@hotwired/stimulus';
import { sfx } from '../audio/sfx.js';

/* Ile trwa dohamowanie przy zerowym i pełnym zamachu. Nawet lekkie stuknięcie
   ma dać widowisko, a pełny zamach nie może nudzić czekaniem. */
const SPIN_MS_MIN = 2200;
const SPIN_MS_MAX = 5200;

/* Pełne obroty ponad dojazd do sektora - też skalowane siłą zamachu. */
const TURNS_MIN = 2;
const TURNS_MAX = 8;

/**
 * Panel koła fortuny na ekranie hosta.
 *
 * Koło **nie losuje**. Wynik zapada na serwerze (spec 14) i przychodzi eventem
 * `wheel_result` z numerem sektora; animacja tylko dokręca tarczę tak, żeby ten
 * sektor zatrzymał się pod wskaźnikiem. Dzięki temu nie da się wygrać punktów
 * przez podmianę czegokolwiek w karcie telewizora.
 */
export default class extends Controller {
    static targets = ['categoryPicker', 'categorySelect', 'progress', 'board', 'hintText',
        'wheel', 'turn', 'answer', 'event', 'controls', 'hint'];
    static values = { actionUrl: String, viewUrl: String };

    connect() {
        this.angle = 0;
        this.sectors = [];
        this.spinning = false;

        // Nazwy drużyn bierzemy z tablo wyrenderowanego przez serwer. Sam
        // nasłuch zdarzeń nie wystarczy: drużyny dołączyły, zanim host wybrał
        // grę, więc "team_joined" dawno przeleciało i tura pokazywałaby id.
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
        cancelAnimationFrame(this.frame);
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
        await this.#send(event.currentTarget.dataset.koloAction);
    }

    #onServerEvent(event) {
        switch (event.type) {
            case 'wheel_result':
                // Serwer już zna wynik; animacja go tylko pokazuje, a po jej
                // zakończeniu meldujemy zatrzymanie i gra rusza dalej.
                this.#startSpin(event.index, event.power);

                return;

            case 'team_joined':
                this.teams.set(event.team.id, event.team.name);
                break;

            case 'letter_revealed':
                this.#flash(0 === event.count
                    ? `Litery ${event.letter} nie ma`
                    : `${event.letter} × ${event.count}`);

                if (0 === event.count) {
                    sfx.play('wrong');
                } else {
                    // Klik za każdy kafelek gra przy jego obrocie (#turn),
                    // a nagroda dopiero za ostatnim - inaczej wchodziłaby
                    // w środek liczenia „ile ich jest".
                    sfx.play('point', { delay: event.count * 0.14 });
                }
                break;

            case 'penalty':
                this.#flash('bankrut' === event.kind ? 'BANKRUT!' : 'STOP — koniec tury');
                sfx.play('bankrut' === event.kind ? 'bankrupt' : 'wrong');
                break;

            case 'solve_failed':
                this.#flash('pudło');
                sfx.play('wrong');
                break;

            case 'puzzle_solved':
                this.#flash(`${event.teamName} rozwiązuje! +${event.won}`);
                sfx.play('victory');
                break;

            case 'puzzle_ready':
                sfx.play('whoosh');
                break;
        }

        if (['phase', 'letter_revealed', 'penalty', 'puzzle_solved', 'puzzle_ready',
            'category_picked', 'turn', 'solve_failed', 'reset'].includes(event.type)) {
            this.refresh();
        }
    }

    #render(view) {
        this.sectors = view.sectors ?? [];
        this.categoryPickerTarget.hidden = Boolean(view.category);

        if (!view.category) {
            this.#fillCategories(view.categories ?? []);
        }

        this.progressTarget.textContent = view.category
            ? `${view.categoryName} — hasło ${view.puzzleNumber} z ${view.puzzleTotal}`
            : '';

        this.#renderBoard(view.mask);
        // Sama podpowiedź - kategoria stoi już w pasku obok numeru hasła.
        this.hintTextTarget.textContent = view.hint ?? '';
        // Hasło pokazujemy WYŁĄCZNIE po rozwiązaniu. Ekran hosta to telewizor,
        // na który patrzy cały pokój - "host widzi rozwiązanie" ma sens przy
        // osobnym urządzeniu prowadzącego, a nie tutaj. Wcześniej hasło stało
        // na ekranie od chwili wylosowania i gra nie miała czego zgadywać.
        // Serwer i tak sam ocenia odpowiedź (akcja 'solve'), więc nikomu ta
        // podpowiedź nie była do niczego potrzebna.
        this.answerTarget.textContent = 'solved' === view.state ? (view.phrase ?? '') : '';
        this.turnTarget.textContent = view.turn ? `Tura: ${this.#teamName(view.turn)}` : '';

        this.#drawWheel();
        this.#renderControls(view);
        this.#resumeSpin(view);
    }

    /**
     * Jedyne wejście w animację - pilnuje, żeby nie ruszyła dwa razy naraz.
     * Dwie pętle rAF pisałyby po tym samym kącie i obie zameldowały `land`,
     * a drugie zgłoszenie jest już niedozwolonym przejściem.
     */
    #startSpin(index, power) {
        if (this.spinning || undefined === index || null === index) {
            return;
        }

        // Rozpędzenie tarczy - potem prowadzi już klekot kołków (wheelTick).
        sfx.play('spin');
        this.#spinTo(index, power).then(() => this.#send('land'));
    }

    /**
     * Dokańcza kręcenie, o którym ten ekran nie usłyszał.
     *
     * `wheel_result` leci raz i tylko do podłączonych klientów. Jeśli telewizor
     * akurat się przeładowywał (po wyborze gry robi to sam) albo na moment
     * zgubił SSE, event przepada bezpowrotnie - a wtedy gra stoi w `spinning`
     * na zawsze: host nigdy nie zamelduje `land`, telefon nie ma czym ruszyć
     * i jedynym wyjściem jest reset. Stan i wynik koła są w widoku, więc po
     * każdym odświeżeniu można podjąć kręcenie tam, gdzie się urwało.
     */
    #resumeSpin(view) {
        if ('spinning' === view.state) {
            this.#startSpin(view.wheel?.index, view.wheel?.power);
        }
    }

    /**
     * Plansza hasła. Kafelek ma dwie strony: zakrytą i literę na odwrocie -
     * odsłonięcie to obrót o 180 stopni, a nie podmiana tekstu.
     *
     * Dlatego plansza przebudowuje się TYLKO przy nowym haśle. Przy trafionej
     * literze podmieniamy pojedyncze kafelki, bo przebudowa całości obróciłaby
     * wszystkie naraz - łącznie z tymi, które leżą odkryte od trzech rund.
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

        // Litera potrafi wystąpić kilka razy - kafelki obracają się jeden po
        // drugim, od lewej. Wszystkie naraz to błysk, którego oko nie nadąża
        // policzyć, a przy stole liczy się właśnie „ile ich jest".
        let delay = 0;

        [...mask].forEach((char, index) => {
            if (char === this.mask[index]) {
                return;
            }

            this.#turn(this.boardTarget.children[index], char, delay);
            delay += 140;
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

        inner.append(back, front);
        cell.append(inner);

        // Litera odkryta przed wejściem na ten ekran (odświeżenie strony
        // w połowie gry) leży już obrócona - bez animacji, bo nikt jej
        // przed chwilą nie zgadł.
        if ('_' !== char) {
            front.textContent = char;
            inner.classList.add('revealed');
        }

        return cell;
    }

    #turn(cell, char, delay) {
        const inner = cell?.querySelector('.cell-inner');

        if (!inner) {
            return;
        }

        inner.querySelector('.cell-front').textContent = char;
        setTimeout(() => inner.classList.add('revealed'), delay);
        // Dźwięk planujemy z tym samym opóźnieniem, ale w zegarze karty
        // dźwiękowej - setTimeout potrafi się spóźnić o kilkanaście
        // milisekund i klik odkleiłby się od obrotu kafelka.
        sfx.play('flip', { delay: delay / 1000 });
    }

    #renderControls(view) {
        const buttons = {
            idle: view.category ? [['next_puzzle', 'Losuj hasło', 'primary']] : [],
            next_team: [['spin', 'Zakręć kołem', 'primary'], ['skip_turn', 'Oddaj turę', '']],
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
            idle: view.category ? 'Wylosuj hasło, żeby zacząć.' : 'Wybierz kategorię.',
            next_team: 'Drużyna kręci kołem — z telefonu albo stąd.',
            spinning: 'Koło się kręci…',
            pick_letter: 'Drużyna podaje literę na telefonie.',
            solved: 'Hasło rozwiązane.',
        }[view.state] ?? '';
    }

    #teamName(teamId) {
        return this.teams.get(teamId) ?? 'drużyna';
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

    /** Dokręca tarczę tak, żeby wskazany sektor stanął pod wskaźnikiem u góry. */
    #spinTo(index, power) {
        const count = this.sectors.length;

        if (!count) {
            return Promise.resolve();
        }

        this.spinning = true;

        const turn = 2 * Math.PI;
        const sector = turn / count;
        // Kąt tarczy, przy którym środek wylosowanego sektora stoi pod
        // wskaźnikiem u góry (-90°). Połówka sektora to właśnie "środek,
        // a nie krawędź".
        const target = -Math.PI / 2 - (index + 0.5) * sector;
        const from = this.angle;
        // Ile jeszcze brakuje do celu, licząc w stronę, w którą kręci animacja
        // (kąt maleje). Podwójne modulo, bo % w JS zachowuje znak dzielnej
        // i dla ujemnej różnicy zwróciłoby wynik ujemny, czyli obrót w tył.
        const remaining = (((from - target) % turn) + turn) % turn;
        // Pełne obroty dla efektu + dojazd do celu. Odejmowanie samego
        // "remaining" jest tym, co ustawia sektor pod wskaźnikiem; wcześniej
        // ten składnik znosił się z celem i tarcza wracała zawsze do kąta
        // wyjściowego - czyli przy dwunastu polach dokładnie na styk sektorów,
        // niezależnie od tego, co wylosował serwer.
        // Siła zamachu z telefonu (0-100) zmienia WYŁĄCZNIE widowisko: liczbę
        // obrotów i czas dohamowania. Sektor wybrał serwer i to on siedzi
        // w `remaining` - mocniejszy zamach dokłada tylko pełne obroty, więc
        // koło i tak stanie tam, gdzie zapadła decyzja.
        const strength = Math.min(1, Math.max(0, (Number(power) || 50) / 100));
        const turns = Math.round(TURNS_MIN + (TURNS_MAX - TURNS_MIN) * strength);
        const duration = SPIN_MS_MIN + (SPIN_MS_MAX - SPIN_MS_MIN) * strength;

        const to = from - remaining - turns * turn;

        return new Promise((resolve) => {
            const start = performance.now();
            // Klapka o kołki: klik pada dokładnie wtedy, gdy pod wskaźnikiem
            // zmienia się sektor. Liczymy to z bieżącego kąta zamiast rozstawiać
            // kliki z góry - dzięki temu ratchet zwalnia razem z tarczą sam,
            // bez powtarzania tu wzoru na wyhamowanie.
            let passed = Math.floor((-Math.PI / 2 - from) / sector);

            const step = (now) => {
                const t = Math.min(1, (now - start) / duration);
                // easeOutQuart: szybki start, długie dohamowanie - tak zwalnia
                // prawdziwe koło i to ono buduje napięcie przy stole.
                this.angle = from + (to - from) * (1 - (1 - t) ** 4);
                this.#drawWheel();

                const crossed = Math.floor((-Math.PI / 2 - this.angle) / sector);

                if (crossed !== passed) {
                    // Przy pełnym zamachu tarcza przelatuje ponad sektor na
                    // klatkę - gramy jeden klik, a nie serię naraz.
                    passed = crossed;
                    sfx.play('wheelTick');
                }

                if (t < 1) {
                    this.frame = requestAnimationFrame(step);
                } else {
                    this.spinning = false;
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
