import { Controller } from '@hotwired/stimulus';
import gsap from 'gsap';
import { sfx } from '../audio/sfx.js';
import { create } from '../dutch/cards.js';

/**
 * Panel Dutcha na ekranie hosta (telewizor).
 *
 * Stan gry trzyma serwer; ten kontroler tylko go pokazuje i orkiestruje fazy
 * (rozdanie, start, koniec rundy). Ręce graczy są tajne do końca rundy - do
 * czasu odkrycia panel pokazuje wyłącznie rewersy i to, czyja tura. Po każdej
 * zmianie dociąga widok osobnym żądaniem, tak jak pozostałe gry.
 */
export default class extends Controller {
    static targets = ['table', 'deckCount', 'discard', 'standings',
        'controls', 'hint', 'event', 'tip', 'progressLine'];
    static values = { actionUrl: String, viewUrl: String };

    connect() {
        this.teams = new Map(
            [...document.querySelectorAll('[data-team-id]')].map((tile) => [
                tile.dataset.teamId,
                tile.querySelector('.team-name')?.textContent?.trim() ?? 'gracz',
            ]),
        );

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

    async act(event) {
        await this.#send(event.currentTarget.dataset.dutchAction);
    }

    #onServerEvent(event) {
        switch (event.type) {
            case 'dealt':
                sfx.play('whoosh');
                break;

            case 'drew':
                // Rewers leci z talii do gracza - na TV nie widać wartości.
                this.#drawFly(event.teamId);
                sfx.play('flip');
                break;

            case 'swapped':
            case 'discarded':
                // Karta wylatuje z miejsca gracza i ląduje na stosie na środku.
                this.#fly(event.teamId, event.discardTop);
                sfx.play('flip');

                if ('peek_own' === event.power) {
                    this.#flash(`${this.#name(event.teamId)} podgląda swoją kartę (dama)`);
                    sfx.play('sparkle');
                }
                break;

            case 'blind_swapped':
                this.#flash(`${this.#name(event.teamId)} zamienia karty (walet)`);
                sfx.play('whoosh');
                break;

            case 'snapped':
                // Dorzucona karta leci na stos jak każda inna.
                this.#fly(event.teamId, event.discardTop);
                this.#flash(`${this.#name(event.teamId)} dorzuca kartę!`);
                sfx.play('point');
                break;

            case 'snap_failed':
                this.#flash(`${this.#name(event.teamId)} — pudło, karna karta`);
                // Kara: karta, którą chciał wyrzucić, stoi na wierzchu przez 3 s.
                this.#showMisSnap(event.card);
                sfx.play('wrong');
                break;

            case 'called':
                this.#flash(`${this.#name(event.teamId)} woła „DUTCH”!`);
                sfx.play('armed');
                break;

            case 'revealed':
                this.#announce(event.scores);
                break;

            case 'peek_progress':
                // Ktoś podejrzał kartę - odświeżenie pokaże aktualne „X/2" na stole.
                if (event.peeked >= (event.total ?? 2)) {
                    this.#flash(`${this.#name(event.teamId)} gotowy`);
                }
                break;

            case 'team_joined':
                this.teams.set(event.team.id, event.team.name);
                break;
        }

        if (['phase', 'dealt', 'drew', 'swapped', 'discarded', 'blind_swapped', 'snapped', 'snap_failed',
            'called', 'turn', 'peek_progress', 'revealed', 'round_reset', 'team_joined', 'reset'].includes(event.type)) {
            const done = this.refresh();

            // Animacje odpalamy PO odświeżeniu, gdy nowe kafelki są już w DOM-ie.
            if ('dealt' === event.type) {
                done.then(() => this.#dealCascade());
            } else if ('revealed' === event.type) {
                done.then(() => this.#revealFlip());
            }
        }
    }

    /** Odkrycie kart: oklaski dla wygranych, buczenie dla pudlacza z „Dutch”. */
    #announce(scores) {
        if (scores && false === scores.callCorrect) {
            sfx.play('wrong');

            return;
        }

        sfx.play('correct');
        sfx.play('applause', { delay: 0.55 });
    }

    #render(view) {
        this.element.dataset.state = view.state;

        this.deckCountTarget.textContent = view.deckCount ?? '—';
        this.#renderDiscard(view.discardTail);

        this.progressLineTarget.textContent = view.round
            ? `Runda ${view.round}${'final' === view.state ? ' — ostatnie tury' : ''}`
            : '';

        this.#renderTable(view);
        this.#renderStandings(view);
        this.#renderControls(view);

        this.tipTarget.hidden = 'idle' !== view.state && 'peek' !== view.state;
    }

    /** Stos: ostatnie do dwóch kart, lekko przesunięte, wierzchnia na wierzchu. */
    #renderDiscard(tail) {
        const cards = Array.isArray(tail) ? tail : [];

        if (0 === cards.length) {
            const empty = document.createElement('span');
            empty.className = 'dutch-card dutch-empty';
            this.discardTarget.replaceChildren(empty);

            return;
        }

        this.discardTarget.replaceChildren(...cards.map((card, index) => {
            const cell = create(card);
            cell.classList.add('dutch-pile-card');
            cell.style.setProperty('--i', String(index));

            return cell;
        }));
    }

    /**
     * Stół hosta w kompaktowych wierszach: nazwa + LICZBA kart. Siatka kart
     * każdego gracza nie zmieściłaby się przy sześciu graczach, a na TV liczy
     * się czytelność całej gry. Prawdziwe karty pokazujemy dopiero przy
     * odsłonięciu - to jedyny moment, gdy są jawne.
     */
    #renderTable(view) {
        const reveal = 'reveal' === view.state;
        const revealed = view.revealed ?? null;
        const sums = view.scores?.sums ?? {};
        const winners = new Set(view.scores?.winners ?? []);

        this.tableTarget.replaceChildren(...(view.players ?? []).map((player) => {
            const seat = document.createElement('div');
            seat.className = 'dutch-seat';
            seat.dataset.teamId = player.id;
            seat.style.setProperty('--team-color', player.color);

            if (view.turn === player.id) {
                seat.classList.add('is-turn');
            }
            if (view.calledBy === player.id) {
                seat.classList.add('is-caller');
            }
            if (winners.has(player.id)) {
                seat.classList.add('is-winner');
            }

            const name = document.createElement('span');
            name.className = 'dutch-seat-name';
            name.textContent = player.name;

            if (view.calledBy === player.id) {
                const flag = document.createElement('span');
                flag.className = 'dutch-flag';
                flag.textContent = 'DUTCH';
                name.append(flag);
            }

            seat.append(name);

            const cards = reveal ? (revealed?.[player.id] ?? null) : null;

            if (cards) {
                // Odsłonięcie: małe karty i suma - jedyny moment, gdy są jawne.
                seat.classList.add('is-open');

                const strip = document.createElement('div');
                strip.className = 'dutch-seat-open';
                cards.forEach((card) => strip.append(card ? this.#miniCard(card) : this.#miniGap()));

                const sum = document.createElement('span');
                sum.className = 'dutch-seat-sum';
                sum.textContent = `${sums[player.id] ?? 0} pkt`;

                seat.append(strip, sum);

                return seat;
            }

            // W grze: sama liczba kart. Podczas podglądu - ile kart obejrzał.
            const shape = view.hands?.[player.id] ?? Array.from({ length: view.handSize ?? 4 }, () => true);
            const info = document.createElement('span');
            info.className = 'dutch-seat-count';

            if ('peek' === view.state) {
                const peek = document.createElement('span');
                peek.className = 'dutch-seat-peek';
                peek.textContent = `podejrzał ${view.peekProgress?.[player.id] ?? 0}/${view.peekLimit ?? 2}`;
                info.append(peek);
            }

            const back = document.createElement('span');
            back.className = 'dutch-mini-back';

            const num = document.createElement('b');
            num.textContent = String(shape.filter(Boolean).length);

            const unit = document.createElement('span');
            unit.className = 'dutch-seat-unit';
            unit.textContent = 'kart';

            info.append(back, num, unit);
            seat.append(info);

            return seat;
        }));
    }

    /** Mała karta (awers) do paska odsłonięcia. */
    #miniCard(card) {
        const cell = create(card);
        cell.classList.add('dutch-mini');

        return cell;
    }

    /** Mała luka po dorzuconej karcie - na pasku odsłonięcia. */
    #miniGap() {
        const cell = document.createElement('span');
        cell.className = 'dutch-card dutch-empty dutch-mini';

        return cell;
    }

    #backCard() {
        const cell = document.createElement('span');
        cell.className = 'dutch-card dutch-down';

        return cell;
    }

    /**
     * Dobranie: rewers leci z talii na miejsce gracza (GSAP). Wartości nie
     * pokazujemy - to karta trafiająca w ciemno do ręki, więc leci zakryta.
     */
    #drawFly(teamId) {
        if (this.#reduced()) {
            return;
        }

        const deck = this.element.querySelector('.dutch-deck');
        const seat = this.tableTarget.querySelector(`[data-team-id="${teamId}"]`);

        if (!deck || !seat) {
            return;
        }

        const from = deck.getBoundingClientRect();
        const to = seat.getBoundingClientRect();
        const dx = (to.left + to.width / 2) - (from.left + from.width / 2);
        const dy = (to.top + to.height / 2) - (from.top + from.height / 2);

        const fly = this.#backCard();
        fly.classList.add('dutch-fly');
        fly.style.left = `${from.left + from.width / 2}px`;
        fly.style.top = `${from.top + from.height / 2}px`;
        document.body.append(fly);

        gsap.timeline({ onComplete: () => fly.remove() })
            .set(fly, { xPercent: -50, yPercent: -50, scale: 1.2, rotation: 0 })
            .to(fly, { duration: 0.7, x: dx, y: dy, scale: 1, rotation: gsap.utils.random(-8, 8), ease: 'power2.out' })
            .to(fly, { duration: 0.28, scale: 0.85, opacity: 0, ease: 'power1.in' }, '-=0.06');
    }

    /**
     * Karta wylatuje z miejsca gracza i ląduje na stosie (GSAP). Element leci
     * w osobnej warstwie nad panelem, więc odświeżenie stołu go nie zabiera.
     * Pozycje bierzemy z DOM-u sprzed odświeżenia.
     */
    #fly(teamId, card) {
        if (!card || this.#reduced()) {
            return;
        }

        const seat = this.tableTarget.querySelector(`[data-team-id="${teamId}"]`);
        const target = this.discardTarget;

        if (!seat || !target) {
            return;
        }

        const from = seat.getBoundingClientRect();
        const to = target.getBoundingClientRect();
        const dx = (to.left + to.width / 2) - (from.left + from.width / 2);
        const dy = (to.top + to.height / 2) - (from.top + from.height / 2);

        const fly = create(card);
        fly.classList.add('dutch-fly');
        fly.style.left = `${from.left + from.width / 2}px`;
        fly.style.top = `${from.top + from.height / 2}px`;
        document.body.append(fly);

        // Łuk: najpierw podskok, potem zjazd na stos, z obrotem i lądowaniem.
        gsap.timeline({ onComplete: () => fly.remove() })
            .set(fly, { xPercent: -50, yPercent: -50, scale: 0.6, rotation: -12 })
            .to(fly, { duration: 0.85, x: dx, y: dy, scale: 1, rotation: 8, ease: 'power2.inOut' })
            .to(fly, { duration: 0.18, scale: 1.08, yoyo: true, repeat: 1, ease: 'power1.out' }, '-=0.09');
    }

    /**
     * Rozdanie: rewersy wylatują z talii do każdego gracza, jeden po drugim.
     * Stół pokazuje tylko liczbę kart, więc animujemy lecące kopie, nie kafelki.
     */
    #dealCascade() {
        if (this.#reduced()) {
            return;
        }

        const deck = this.element.querySelector('.dutch-deck');
        const seats = [...this.tableTarget.querySelectorAll('.dutch-seat')];

        if (!deck || 0 === seats.length) {
            return;
        }

        const d = deck.getBoundingClientRect();
        const dcx = d.left + d.width / 2;
        const dcy = d.top + d.height / 2;

        // Przy wielu graczach mniej kart na gracza, żeby lot nie ciągnął się bez końca.
        const perSeat = Math.max(1, Math.round(10 / seats.length));
        let delay = 0;

        seats.forEach((seat) => {
            const s = seat.getBoundingClientRect();
            const dx = (s.left + s.width / 2) - dcx;
            const dy = (s.top + s.height / 2) - dcy;

            for (let i = 0; i < perSeat; i += 1) {
                const fly = this.#backCard();
                fly.classList.add('dutch-fly');
                fly.style.left = `${dcx}px`;
                fly.style.top = `${dcy}px`;
                document.body.append(fly);

                gsap.timeline({ delay, onComplete: () => fly.remove() })
                    .set(fly, { xPercent: -50, yPercent: -50, scale: 1.1 })
                    .to(fly, { duration: 0.55, x: dx, y: dy, scale: 1, rotation: gsap.utils.random(-10, 10), ease: 'power2.out' })
                    .to(fly, { duration: 0.2, scale: 0.85, opacity: 0, ease: 'power1.in' }, '-=0.05');

                delay += 0.12;
            }
        });
    }

    /** Odsłonięcie: karty obracają się awersem do góry, jedna po drugiej. */
    #revealFlip() {
        if (this.#reduced()) {
            return;
        }

        const cards = this.tableTarget.querySelectorAll('.dutch-card.dutch-up');

        if (0 === cards.length) {
            return;
        }

        gsap.from(cards, {
            duration: 0.7,
            rotationY: 90,
            opacity: 0,
            transformPerspective: 500,
            transformOrigin: 'center',
            stagger: 0.12,
            ease: 'power2.out',
        });
    }

    #reduced() {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    /** Nietrafione dorzucenie: pokazuje kartę wszystkim na 3 sekundy. */
    #showMisSnap(card) {
        if (!card) {
            return;
        }

        clearTimeout(this.misSnapTimer);

        const wrap = document.createElement('div');
        wrap.className = 'dutch-missnap';

        const label = document.createElement('p');
        label.className = 'dutch-missnap-label';
        label.textContent = 'Chciał wyrzucić:';

        const cell = create(card);
        cell.classList.add('dutch-missnap-card');

        wrap.append(label, cell);
        document.body.append(wrap);

        this.misSnapTimer = setTimeout(() => wrap.remove(), 3000);
    }

    #renderStandings(view) {
        const totals = view.totals ?? {};
        const ids = Object.keys(totals);

        if (0 === ids.length) {
            this.standingsTarget.replaceChildren();

            return;
        }

        ids.sort((a, b) => totals[a] - totals[b]);

        const rows = ids.map((id, index) => {
            const row = document.createElement('div');
            row.className = 'dutch-rank-row';

            const place = document.createElement('span');
            place.className = 'dutch-rank-place';
            place.textContent = `${index + 1}.`;

            const name = document.createElement('span');
            name.className = 'dutch-rank-name';
            name.textContent = this.#name(id);

            const pts = document.createElement('span');
            pts.className = 'dutch-rank-pts';
            pts.textContent = `${totals[id]} pkt`;

            row.append(place, name, pts);

            return row;
        });

        const title = document.createElement('p');
        title.className = 'dutch-rank-title';
        title.textContent = 'Ranking (mniej = lepiej)';

        this.standingsTarget.replaceChildren(title, ...rows);
    }

    #renderControls(view) {
        const buttons = {
            idle: [['deal', 'Rozdaj karty', 'primary', !view.canDeal]],
            peek: [['begin', 'Zaczynamy', 'primary', false]],
            playing: [['finish', 'Zakończ rundę', '', false]],
            final: [['finish', 'Zakończ rundę', '', false]],
            reveal: [['next_round', 'Nowa runda', 'primary', false]],
        }[view.state] ?? [];

        this.controlsTarget.replaceChildren(...buttons.map(([action, label, variant, disabled]) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `btn btn-lg${'primary' === variant ? ' btn-primary' : ''}`;
            button.textContent = label;
            button.disabled = Boolean(disabled);
            button.dataset.dutchAction = action;
            button.dataset.action = 'dutch-host#act';

            return button;
        }));

        this.hintTarget.textContent = this.#hint(view);
    }

    #hint(view) {
        if ('idle' === view.state) {
            return view.canDeal ? 'Rozdaj karty, żeby zacząć rundę.' : 'Potrzeba co najmniej dwóch graczy.';
        }
        if ('peek' === view.state) {
            return 'Gracze podglądają po dwie karty. Zacznij, gdy będą gotowi.';
        }
        if ('playing' === view.state || 'final' === view.state) {
            const turn = `Tura: ${this.#name(view.turn)}.`;

            return 'final' === view.state ? `${turn} Runda końcowa — reszta gra ostatni raz.` : turn;
        }
        if ('reveal' === view.state) {
            return this.#winnerLine(view);
        }

        return '';
    }

    #winnerLine(view) {
        const winners = (view.scores?.winners ?? []).map((id) => this.#name(id));

        if (0 === winners.length) {
            return 'Runda skończona.';
        }

        return `Wygrywa: ${winners.join(', ')}.`;
    }

    #name(id) {
        return this.teams.get(id) ?? 'gracz';
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

        if (!response.ok) {
            const problem = await response.json().catch(() => ({}));
            this.hintTarget.textContent = problem.detail ?? 'Nie udało się wykonać akcji.';

            return;
        }

        this.refresh();
    }
}
