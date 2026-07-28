import { Controller } from '@hotwired/stimulus';
import gsap from 'gsap';
import { sfx } from '../audio/sfx.js';
import { celebrate, shake, popIn } from '../anim.js';

/* Ile trwa dohamowanie przy zerowym i pełnym zamachu. Wolniej = więcej napięcia:
   nawet lekkie stuknięcie kręci długo, a pełny zamach to spore widowisko. */
const SPIN_MS_MIN = 3800;
const SPIN_MS_MAX = 8500;

/* Pełne obroty ponad dojazd do sektora - też skalowane siłą zamachu. */
const TURNS_MIN = 4;
const TURNS_MAX = 10;

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
            const done = this.refresh();

            // Rozwiązane hasło świętuje, bankrut potrząsa kołem, nowe hasło
            // wskakuje z powiększenia.
            if ('puzzle_solved' === event.type) {
                done.then(() => celebrate(this.boardTarget));
            } else if ('penalty' === event.type && 'bankrut' === event.kind) {
                done.then(() => shake(this.wheelTarget));
            } else if ('puzzle_ready' === event.type) {
                done.then(() => popIn(this.boardTarget));
            }
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

        this.landedIndex = null;
        // Rozpędzenie tarczy - potem prowadzi już klekot kołków (wheelTick).
        sfx.play('spin');
        this.#spinTo(index, power).then(() => {
            this.landedIndex = index;
            this.#drawWheel();
            // Wylosowane pole powiększa się na cały ekran - widać, co padło.
            this.#showSector(index);
            this.#send('land');
        });
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
            this.cells = [];
            this.mask = '';

            return;
        }

        if (this.mask?.length !== mask.length) {
            this.mask = mask;
            this.#buildBoard(mask);

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

            this.#turn(this.cells[index], char, delay);
            delay += 140;
        });

        this.mask = mask;
    }

    /**
     * Buduje planszę słowo po słowie: litery jednego wyrazu siedzą we wspólnym,
     * nierozrywalnym kontenerze (.puzzle-word), więc wyraz nigdy nie przechodzi
     * w połowie do następnego wiersza - plansza łamie się tylko między wyrazami.
     *
     * this.cells trzyma płaskie odwzorowanie „pozycja w haśle -> kafelek" (null
     * dla spacji), żeby odkrywanie liter po indeksie nie zależało od tego, jak
     * kafelki są pogrupowane w drzewie.
     */
    #buildBoard(mask) {
        this.cells = [];
        const words = [];
        let word = null;

        [...mask].forEach((char) => {
            if (' ' === char) {
                word = null;
                this.cells.push(null);

                return;
            }

            if (!word) {
                word = document.createElement('span');
                word.className = 'puzzle-word';
                words.push(word);
            }

            const cell = this.#cell(char);
            word.append(cell);
            this.cells.push(cell);
        });

        this.boardTarget.replaceChildren(...words);
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
        const duration = (SPIN_MS_MIN + (SPIN_MS_MAX - SPIN_MS_MIN) * strength) / 1000;

        const to = from - remaining - turns * turn;

        return new Promise((resolve) => {
            // Klapka o kołki: klik pada dokładnie wtedy, gdy pod wskaźnikiem
            // zmienia się sektor. Liczymy to z bieżącego kąta zamiast rozstawiać
            // kliki z góry - dzięki temu ratchet zwalnia razem z tarczą sam.
            let passed = Math.floor((-Math.PI / 2 - from) / sector);
            const proxy = { a: from };

            // GSAP power4.out: długie, aksamitne dohamowanie - buduje napięcie
            // i wygląda płynniej niż ręczna pętla rAF.
            gsap.to(proxy, {
                a: to,
                duration,
                ease: 'power4.out',
                onUpdate: () => {
                    this.angle = proxy.a;
                    this.#drawWheel();

                    const crossed = Math.floor((-Math.PI / 2 - this.angle) / sector);

                    if (crossed !== passed) {
                        passed = crossed;
                        sfx.play('wheelTick');
                    }
                },
                onComplete: () => {
                    this.spinning = false;
                    resolve();
                },
            });
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
        // Większy margines: mieści duży wskaźnik nad tarczą i jej cień.
        const r = Math.min(cx, cy) - canvas.width * 0.08;
        const sector = (2 * Math.PI) / count;

        // Cień pod całą tarczą - odrywa ją od tła.
        ctx.save();
        ctx.shadowColor = 'rgba(0, 0, 0, 0.55)';
        ctx.shadowBlur = r * 0.09;
        ctx.shadowOffsetY = r * 0.04;
        ctx.beginPath();
        ctx.arc(cx, cy, r, 0, 2 * Math.PI);
        ctx.fillStyle = '#0b0d17';
        ctx.fill();
        ctx.restore();

        this.sectors.forEach((value, i) => {
            const start = this.angle + i * sector;

            ctx.beginPath();
            ctx.moveTo(cx, cy);
            ctx.arc(cx, cy, r, start, start + sector);
            ctx.closePath();
            ctx.fillStyle = this.#sectorColor(value, i);
            ctx.fill();

            // Rozdzielacz między polami - tarcza wygląda schludniej.
            ctx.lineWidth = r * 0.008;
            ctx.strokeStyle = 'rgba(255, 255, 255, 0.22)';
            ctx.stroke();

            // Duży, czytelny napis wzdłuż promienia.
            ctx.save();
            ctx.translate(cx, cy);
            ctx.rotate(start + sector / 2);
            ctx.fillStyle = 'bankrut' === value ? '#fff' : '#10121f';
            ctx.font = `800 ${Math.round(r * 0.115)}px system-ui, sans-serif`;
            ctx.textAlign = 'right';
            ctx.textBaseline = 'middle';
            ctx.fillText(String(value).toUpperCase(), r - r * 0.07, 0);
            ctx.restore();
        });

        // Podświetlenie wylosowanego pola - jasna obwódka, gdy tarcza stanęła.
        if (null != this.landedIndex && !this.spinning) {
            const start = this.angle + this.landedIndex * sector;
            ctx.beginPath();
            ctx.moveTo(cx, cy);
            ctx.arc(cx, cy, r, start, start + sector);
            ctx.closePath();
            ctx.lineWidth = r * 0.022;
            ctx.strokeStyle = '#f5f6fb';
            ctx.stroke();
        }

        // Głębia: przyciemnienie przy krawędzi daje wrażenie wypukłej kopuły.
        const depth = ctx.createRadialGradient(cx, cy, r * 0.2, cx, cy, r);
        depth.addColorStop(0, 'rgba(0, 0, 0, 0)');
        depth.addColorStop(0.8, 'rgba(0, 0, 0, 0)');
        depth.addColorStop(1, 'rgba(0, 0, 0, 0.3)');
        ctx.fillStyle = depth;
        ctx.beginPath();
        ctx.arc(cx, cy, r, 0, 2 * Math.PI);
        ctx.fill();

        // Gloss: rozświetlenie u góry.
        const gloss = ctx.createRadialGradient(cx, cy - r * 0.5, r * 0.1, cx, cy, r);
        gloss.addColorStop(0, 'rgba(255, 255, 255, 0.26)');
        gloss.addColorStop(0.55, 'rgba(255, 255, 255, 0)');
        ctx.fillStyle = gloss;
        ctx.beginPath();
        ctx.arc(cx, cy, r, 0, 2 * Math.PI);
        ctx.fill();

        // Obręcz: ciemna z gorącym, złotym rantem.
        ctx.lineWidth = r * 0.05;
        ctx.strokeStyle = '#0b0d17';
        ctx.beginPath();
        ctx.arc(cx, cy, r + r * 0.025, 0, 2 * Math.PI);
        ctx.stroke();
        ctx.lineWidth = r * 0.022;
        ctx.strokeStyle = '#f5a524';
        ctx.beginPath();
        ctx.arc(cx, cy, r + r * 0.004, 0, 2 * Math.PI);
        ctx.stroke();

        // Piasta z gwiazdką w środku.
        ctx.beginPath();
        ctx.arc(cx, cy, r * 0.15, 0, 2 * Math.PI);
        ctx.fillStyle = '#151829';
        ctx.fill();
        ctx.lineWidth = r * 0.016;
        ctx.strokeStyle = '#f5a524';
        ctx.stroke();
        ctx.fillStyle = '#f5a524';
        ctx.font = `700 ${Math.round(r * 0.17)}px system-ui, sans-serif`;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText('★', cx, cy + r * 0.005);

        // Duży wskaźnik nad tarczą, z cieniem i czerwonym gwoździem.
        const wheelTop = cy - r;
        const pw = r * 0.12;
        const tip = wheelTop + r * 0.06;
        const baseY = wheelTop - r * 0.13;

        ctx.save();
        ctx.shadowColor = 'rgba(0, 0, 0, 0.5)';
        ctx.shadowBlur = r * 0.04;
        ctx.shadowOffsetY = r * 0.015;
        ctx.beginPath();
        ctx.moveTo(cx - pw, baseY);
        ctx.lineTo(cx + pw, baseY);
        ctx.lineTo(cx, tip);
        ctx.closePath();
        ctx.fillStyle = '#f5f6fb';
        ctx.fill();
        ctx.restore();

        ctx.lineWidth = r * 0.012;
        ctx.strokeStyle = '#0b0d17';
        ctx.beginPath();
        ctx.moveTo(cx - pw, baseY);
        ctx.lineTo(cx + pw, baseY);
        ctx.lineTo(cx, tip);
        ctx.closePath();
        ctx.stroke();

        ctx.beginPath();
        ctx.arc(cx, baseY + pw * 0.3, pw * 0.5, 0, 2 * Math.PI);
        ctx.fillStyle = '#e5484d';
        ctx.fill();
        ctx.lineWidth = r * 0.008;
        ctx.strokeStyle = '#0b0d17';
        ctx.stroke();
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

    /**
     * Wylosowane pole powiększa się na cały ekran, żeby było jasne, co padło.
     * Radość dla wygranej, przy bankrut/stop smutek dosyła event kary z serwera.
     */
    #showSector(index) {
        const value = this.sectors[index];
        const bankrut = 'bankrut' === value;
        const stop = 'stop' === value;

        const wrap = document.createElement('div');
        wrap.className = `kolo-result ${bankrut ? 'is-bankrut' : (stop ? 'is-stop' : 'is-win')}`;

        const label = document.createElement('span');
        label.className = 'kolo-result-value';
        label.textContent = String(value).toUpperCase();
        wrap.append(label);
        document.body.append(wrap);

        if (!bankrut && !stop) {
            sfx.play('correct');
            sfx.play('sparkle', { delay: 0.2 });
        }

        if (this.#reduced()) {
            setTimeout(() => wrap.remove(), 1800);

            return;
        }

        gsap.fromTo(label, { scale: 0.2, opacity: 0, rotation: -8 },
            { duration: 0.5, scale: 1, opacity: 1, rotation: 0, ease: 'back.out(1.8)' });
        gsap.to(wrap, { duration: 0.4, scale: 0.7, opacity: 0, delay: 1.7, ease: 'power2.in', onComplete: () => wrap.remove() });
    }

    #reduced() {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
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
