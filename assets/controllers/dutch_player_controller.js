import { Controller } from '@hotwired/stimulus';
import gsap from 'gsap';
import { sfx } from '../audio/sfx.js';
import { confirmDialog } from '../confirm.js';
import { paint, blank, create } from '../dutch/cards.js';

/**
 * Sterownik Dutcha na telefonie.
 *
 * Trasa widoku gry jest dla hosta, więc telefon dostaje stan początkowy jako
 * JSON w szablonie i dalej żyje z eventów - swoje karty wyłącznie przez prywatny
 * topic drużyny (nigdy broadcastem). Model trzymamy lokalnie i mutujemy go
 * kolejnymi zdarzeniami.
 *
 * Uczciwa pamięć: na starcie klikasz, żeby odkryć dwie karty. Zostają czytelne
 * przez dziesięć sekund, po czym same się zakrywają i podgląd jest zamknięty -
 * dalej grasz z głowy. Klik zamiast przytrzymania, bo palec zasłaniał kartę.
 */
export default class extends Controller {
    static targets = ['hand', 'drawnRow', 'controls', 'note', 'turn', 'discard', 'overlay'];
    static values = { actionUrl: String, view: Object };

    /** Ile sekund odkryte karty są widoczne, zanim się zakryją na dobre. */
    static PEEK_MS = 10_000;

    connect() {
        this.m = { ...this.viewValue };
        this.myId = this.m.myId ?? this.element.dataset.teamId;
        this.cache = { ...(this.m.peekedCards ?? {}) };
        this.opened = new Set(Object.keys(this.cache));
        this.pending = new Set();
        this.peekLocked = false;
        this.peekDeadline = null;
        this.builtFor = null;
        // Moc karty: czy wskazujemy kartę do podejrzenia, jaka to moc i co
        // ostatnio zobaczyliśmy.
        this.picking = Boolean(this.m.power);
        this.pickKind = this.m.power ?? null;
        this.pickFirst = null;
        this.seen = null;
        this.offset = 0;

        this.onEvent = (event) => this.#handle(event.detail);
        window.addEventListener('gierki:event', this.onEvent);

        this.#render();

        // Wejście/odświeżenie w trakcie podglądu: licznik rusza od terminu
        // SERWERA (peekDeadline w modelu początkowym), nie od nowa przy każdym
        // odświeżeniu - inaczej odświeżanie strony w kółko trzymałoby karty
        // odkryte bez końca, dopóki reszta stołu czeka na "Zaczynamy".
        if ('peek' === this.m.state && this.m.peekDeadline) {
            this.#syncPeekWindow(this.m.peekDeadline, this.m.serverNow);
        }
    }

    disconnect() {
        window.removeEventListener('gierki:event', this.onEvent);
        this.#stopPeekWindow();
    }

    // --- akcje gracza ---

    async draw(event) {
        const button = event.currentTarget;

        // Bez blokady podwójny tap na telefonie wysyłał "dobierz kartę"
        // dwukrotnie - drugie żądanie i tak dostawało błąd, ale najpierw
        // zdążyło zmylić serwer/UI na chwilę.
        if (button.disabled) {
            return;
        }

        button.disabled = true;

        try {
            await this.#send('draw');
        } finally {
            button.disabled = false;
        }
    }

    async discardDrawn(event) {
        const button = event.currentTarget;

        if (button.disabled) {
            return;
        }

        button.disabled = true;

        try {
            // Dobrana karta wylatuje w górę z telefonu - „rzut na stół".
            this.#throwUp(this.drawnRowTarget.querySelector('.dutch-card'), this.m.drawn);
            await this.#send('discard');
        } finally {
            button.disabled = false;
        }
    }

    async callDutch(event) {
        const button = event.currentTarget;

        if (button.disabled) {
            return;
        }

        button.disabled = true;

        try {
            await this.#send('call');
        } finally {
            button.disabled = false;
        }
    }

    /** Dorzucenie pasującej karty na stos (refleks - dostępne dla każdego). */
    async snap(event) {
        await this.#send('snap', { slot: Number(event.currentTarget.dataset.slot) });
    }

    /** Aktualizuje kształty rąk (ile kart, gdzie luki) po dorzuceniu. */
    #applyHands(hands) {
        if (hands && 'object' === typeof hands) {
            this.m.hands = hands;

            if (Array.isArray(hands[this.myId])) {
                this.m.myHand = hands[this.myId];
            }
        }
    }

    /** Zagranie mocy karty (dama = podejrzyj swoją; walet = zamień karty). */
    async usePower() {
        // Walet jest nieodwracalny: zrzucasz go i losujesz zamianę - dopytujemy.
        if ('swap' === this.m.drawnPower
            && !(await confirmDialog('Zagrać waleta? Zrzucisz go i wskażesz dwóch graczy — zamienię im losowo po jednej karcie.', { confirmLabel: 'Zagraj' }))) {
            return;
        }

        this.#throwUp(this.drawnRowTarget.querySelector('.dutch-card'), this.m.drawn);
        await this.#send('power');
    }

    /** Wskazanie celu mocy: dama = własna karta; walet = dwóch graczy. */
    async pickTarget(event) {
        const teamId = event.currentTarget.dataset.teamId;

        if ('swap' === this.pickKind) {
            // Walet: pierwszy wybrany gracz się zaznacza, drugi odpala zamianę.
            if (!this.pickFirst) {
                this.pickFirst = teamId;
                this.#render();

                return;
            }

            if (this.pickFirst === teamId) {
                return;
            }

            const first = this.pickFirst;
            this.pickFirst = null;
            await this.#send('power_swap', { a: first, b: teamId });

            return;
        }

        await this.#send('power_peek', { teamId, slot: Number(event.currentTarget.dataset.slot) });
    }

    /** Klik w kartę w swojej turze: podmiana za dobraną. */
    async swap(event) {
        if (!this.m.myTurn || !this.m.drawn) {
            return;
        }

        // currentTarget znika po pierwszym await - zapamiętujemy kartę przed oknem.
        const card = event.currentTarget;

        // Dotknięcie karty od razu ją podmienia, a o przypadkowy tap na telefonie
        // nietrudno - dopytujemy, żeby nikt nie zamienił karty przez pomyłkę.
        if (!(await confirmDialog(`Podmienić tę zakrytą kartę na dobraną (${this.m.drawn.label})?`, { confirmLabel: 'Podmień' }))) {
            return;
        }

        // Zamieniana karta (zakryta) wylatuje w górę - reszta ląduje na stole na TV.
        this.#throwUp(card, null);
        await this.#send('swap', { slot: Number(card.dataset.slot) });
    }

    /**
     * Karta wyskakuje z telefonu w górę i znika - jakby poleciała na stół.
     * Leci w osobnej warstwie, więc kolejny render telefonu jej nie zabiera.
     */
    #throwUp(sourceEl, card) {
        if (!sourceEl || this.#reduced()) {
            return;
        }

        const rect = sourceEl.getBoundingClientRect();
        const fly = card ? create(card) : this.#backCard();
        fly.classList.add('dutch-fly');
        fly.style.setProperty('--cw', `${rect.width}px`);
        fly.style.setProperty('--ch', `${rect.height}px`);
        fly.style.left = `${rect.left + rect.width / 2}px`;
        fly.style.top = `${rect.top + rect.height / 2}px`;
        document.body.append(fly);

        // W górę i poza ekran - „rzut na stół" (GSAP).
        gsap.timeline({ onComplete: () => fly.remove() })
            .set(fly, { xPercent: -50, yPercent: -50 })
            .to(fly, {
                duration: 0.65,
                y: -window.innerHeight * 0.9,
                rotation: 16,
                scale: 0.7,
                opacity: 0,
                ease: 'power2.in',
            });
    }

    #backCard() {
        const cell = document.createElement('span');
        cell.className = 'dutch-card dutch-down';

        return cell;
    }

    #reduced() {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    // --- podgląd: klik odkrywa, po 10 s zamyka na dobre ---

    peekTap(event) {
        if ('peek' !== this.m.state || this.peekLocked) {
            return;
        }

        const slot = event.currentTarget.dataset.slot;

        // Już odkryta albo w drodze - nic nie rób. I nie da się otworzyć trzeciej.
        if (this.opened.has(slot) || this.pending.has(slot)) {
            return;
        }

        if (this.opened.size + this.pending.size >= (this.m.peekLimit ?? 2)) {
            return;
        }

        this.pending.add(slot);
        this.#send('peek', { slot: Number(slot) });
    }

    /**
     * Wyrównuje lokalny licznik do AUTORYTATYWNEGO terminu serwera
     * (DutchGame::PEEK_WINDOW_SECONDS, patrz komentarz tam). Serwer i telefon
     * mają różne zegary, więc liczymy offset - dokładnie ten sam wzorzec co
     * przy zegarach kalambur i państw-miast.
     *
     * Termin biegnie od ROZDANIA, nie od pierwszego kliknięcia gracza - inaczej
     * ktoś zwlekający z otwarciem pierwszej karty dostawałby więcej czasu niż
     * ktoś, kto klika od razu. Ten sam mechanizm zamyka lukę bezpieczeństwa:
     * playerView() i tak przestaje oddawać podejrzane karty po tym terminie,
     * więc odświeżanie strony w kółko nic już nie da - to liczy się tylko
     * jako wyświetlacz odliczania.
     */
    #syncPeekWindow(deadline, serverNow) {
        if (!deadline) {
            return;
        }

        this.#stopPeekWindow();
        this.offset = (serverNow ?? Date.now()) - Date.now();
        this.peekDeadline = deadline - this.offset;

        const left = Math.max(0, this.peekDeadline - Date.now());
        this.peekTimer = setTimeout(() => this.#closePeek(), left);
        this.peekTick = setInterval(() => { this.noteTarget.textContent = this.#note(); }, 500);
    }

    #closePeek() {
        this.#stopPeekWindow();
        this.peekLocked = true;
        this.opened.forEach((slot) => this.#hidePeek(slot));
        this.opened = new Set();
        this.pending = new Set();
        // Żaden podgląd już nie wróci - dalej gra się z pamięci.
        this.cache = {};
        this.noteTarget.textContent = this.#note();
    }

    #stopPeekWindow() {
        clearTimeout(this.peekTimer);
        clearInterval(this.peekTick);
        this.peekTimer = null;
        this.peekTick = null;
        this.peekDeadline = null;
    }

    #resetPeek() {
        this.#stopPeekWindow();
        this.cache = {};
        this.opened = new Set();
        this.pending = new Set();
        this.peekLocked = false;
        // Świeża runda kasuje też stan mocy karty.
        this.picking = false;
        this.pickFirst = null;
        this.seen = null;
        clearTimeout(this.seenTimer);
    }

    // --- zdarzenia z serwera ---

    #handle(event) {
        switch (event.type) {
            case 'dealt':
                Object.assign(this.m, {
                    state: 'peek', round: event.round, turn: event.turn,
                    deckCount: event.deckCount, calledBy: null, revealed: null,
                    scores: null, drawn: null,
                });
                this.m.myTurn = event.turn === this.myId;
                // Nowe rozdanie: pełna ręka bez luk.
                this.m.myHand = Array.from({ length: this.m.handSize ?? 4 }, () => true);
                this.#resetPeek();
                this.builtFor = null;
                this.animateDeal = true; // karty wpadną z góry
                // Okno podglądu rusza TERAZ, od terminu serwera - nie czeka na
                // pierwszy klik gracza (patrz #syncPeekWindow).
                this.#syncPeekWindow(event.peekDeadline, event.serverNow);
                break;

            case 'phase':
                this.m.state = event.state;
                // Runda rusza - koniec podglądu, dalej z pamięci.
                if ('playing' === event.state || 'final' === event.state) {
                    this.#resetPeek();
                }
                break;

            case 'peeked': {
                const slot = String(event.slot);
                this.pending.delete(slot);

                // Spóźniona odpowiedź (klik tuż przed zamknięciem okna albo już
                // po starcie rundy) nie może nic odkryć ani wznowić podglądu.
                if ('peek' !== this.m.state || this.peekLocked) {
                    return;
                }

                this.cache[slot] = event.card;
                this.opened.add(slot);
                this.#ensureBuilt();
                this.#showPeek(slot);

                // Okno już liczy się od rozdania (#syncPeekWindow w 'dealt') -
                // to tylko zabezpieczenie, gdyby z jakiegoś powodu nie ruszyło.
                if (!this.peekDeadline) {
                    this.#syncPeekWindow(Date.now() + this.constructor.PEEK_MS, Date.now());
                }

                this.noteTarget.textContent = this.#note();

                return;
            }

            case 'drawn':
                this.m.drawn = event.card;
                // Moc dobranej karty (peek_own / swap / null) - stąd przycisk mocy.
                this.m.drawnPower = event.power ?? null;
                this.animateDrawn = true; // świeżo dobrana - wejdź animacją
                sfx.play('flip');
                break;

            case 'drew':
                this.m.deckCount = event.deckCount;
                if (event.teamId === this.myId) {
                    return; // moja karta przyszła osobno (event 'drawn')
                }
                break;

            case 'turn':
                this.m.turn = event.teamId;
                this.m.myTurn = event.teamId === this.myId;
                this.m.drawn = null;
                this.picking = false;
                this.pickFirst = null;
                if (this.m.myTurn) {
                    sfx.play('armed');
                }
                break;

            case 'power':
                // Zagraliśmy kartę z mocą - teraz wskazujemy kartę do podejrzenia.
                this.picking = true;
                this.pickKind = event.kind;
                break;

            case 'power_seen':
                this.picking = false;
                this.seen = { ownerName: event.ownerName, card: event.card };
                clearTimeout(this.seenTimer);
                this.seenTimer = setTimeout(() => { this.seen = null; this.#render(); }, 8000);
                sfx.play('reveal');
                break;

            case 'swapped':
            case 'discarded':
                this.m.discardTop = event.discardTop;
                this.m.drawn = null;
                break;

            case 'snapped':
                this.m.discardTop = event.discardTop;
                this.#applyHands(event.hands);
                sfx.play(event.teamId === this.myId ? 'point' : 'flip');
                break;

            case 'snap_failed':
                this.m.deckCount = event.deckCount;
                this.#applyHands(event.hands);
                // Kara: przez 3 sekundy wszyscy widzą, jaką kartę chciał wyrzucić.
                if (event.card) {
                    this.snapNote = event.teamId === this.myId
                        ? `Pudło! Karna karta, a wszyscy zobaczyli: ${event.card.label}`
                        : `${this.#name(event.teamId)} się pomylił — wszyscy widzą: ${event.card.label}`;
                    clearTimeout(this.snapNoteTimer);
                    this.snapNoteTimer = setTimeout(() => { this.snapNote = null; this.#render(); }, 3000);
                }
                if (event.teamId === this.myId) {
                    sfx.play('wrong');
                }
                break;

            case 'blind_swapped': {
                // Walet: karta na moim polu zmieniła się w ciemno - jeśli coś
                // z niej pamiętałem (np. z podglądu damą), ta pamięć jest już
                // nieaktualna. Wartość i tak nigdy nie wychodzi na topic,
                // więc jedyne, co mogę zrobić, to ostrzec, które pole "zgasło".
                const mine = [event.a, event.b].find((side) => side.teamId === this.myId);

                if (mine) {
                    this.snapNote = `Walet zamienił Twoją kartę na polu ${mine.slot + 1} - to już inna karta.`;
                    clearTimeout(this.snapNoteTimer);
                    this.snapNoteTimer = setTimeout(() => { this.snapNote = null; this.#render(); }, 4000);
                    sfx.play('whoosh');
                }

                break;
            }

            case 'called':
                this.m.calledBy = event.teamId;
                break;

            case 'revealed':
                Object.assign(this.m, {
                    state: 'reveal', revealed: event.hands, scores: event.scores, totals: event.totals,
                });
                break;

            case 'round_reset':
                Object.assign(this.m, { state: 'idle', revealed: null, scores: null, drawn: null });
                this.#resetPeek();
                this.builtFor = null;
                break;

            case 'reset':
                Object.assign(this.m, { state: 'idle', revealed: null, drawn: null });
                this.#resetPeek();
                this.builtFor = null;
                break;
        }

        this.#render();
    }

    // --- render ---

    #render() {
        const state = this.m.state;
        this.element.dataset.state = state;

        this.#renderTurn();
        this.#renderDiscard();

        if ('peek' === state) {
            this.#ensureBuilt();
        } else {
            this.builtFor = 'peek' === this.builtFor ? null : this.builtFor;
            this.#renderHand();
        }

        this.#renderDrawn();
        this.#renderControls();
        this.#renderOverlay();
        this.noteTarget.textContent = this.#note();
    }

    /** Nakładka damy: wybór karty do podejrzenia albo podejrzana karta. */
    #renderOverlay() {
        if (this.picking) {
            this.overlayTarget.hidden = false;
            this.#renderPicker();

            return;
        }

        if (this.seen) {
            this.overlayTarget.hidden = false;
            this.#renderSeen();

            return;
        }

        this.overlayTarget.hidden = true;
        this.overlayTarget.replaceChildren();
    }

    #renderPicker() {
        // Walet: wybór DWÓCH graczy (zamianę losuje serwer). Dama: własna karta.
        if ('swap' === this.pickKind) {
            this.#renderPlayerPicker();

            return;
        }

        this.#renderOwnCardPicker();
    }

    /** Walet: lista graczy - wskazujesz dwóch, serwer losowo zamienia im po karcie. */
    #renderPlayerPicker() {
        const title = document.createElement('p');
        title.className = 'dutch-overlay-title';
        title.textContent = this.pickFirst
            ? 'Wskaż drugiego gracza'
            : 'Walet: wskaż dwóch graczy — zamienię im losowo po karcie';

        const buttons = (this.m.players ?? []).map((player) => {
            const cards = (this.m.hands?.[player.id] ?? []).filter(Boolean).length;

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-lg dutch-pick-player';
            btn.dataset.teamId = player.id;
            btn.dataset.action = 'click->dutch-player#pickTarget';
            btn.style.setProperty('--team-color', player.color);
            btn.textContent = `${player.id === this.myId ? `${player.name} (Ty)` : player.name} · ${cards} kart`;

            if (this.pickFirst === player.id) {
                btn.classList.add('is-selected');
            }

            return btn;
        });

        // Gdy żaden gracz nie ma już czym się zamienić (albo wybór po prostu
        // nie wychodzi), "Pomiń" kończy turę bez wykonania mocy - zamiast
        // trzymać gracza w martwym punkcie.
        this.overlayTarget.replaceChildren(title, ...buttons, this.#button('power_cancel', 'Pomiń (bez efektu)', 'ghost'));
    }

    /** Dama: podglądasz jedną ze swoich kart. */
    #renderOwnCardPicker() {
        const title = document.createElement('p');
        title.className = 'dutch-overlay-title';
        title.textContent = 'Dotknij swojej karty, którą chcesz podejrzeć';

        const cards = document.createElement('div');
        cards.className = 'dutch-pick-cards';

        (this.m.myHand ?? Array.from({ length: this.m.handSize ?? 4 }, () => true)).forEach((present, slot) => {
            const btn = this.#backCard();
            btn.classList.add('dutch-pick-card');

            if (!present) {
                btn.classList.remove('dutch-down');
                btn.classList.add('dutch-empty');
                cards.append(btn);

                return;
            }

            btn.dataset.teamId = this.myId;
            btn.dataset.slot = String(slot);
            btn.dataset.action = 'click->dutch-player#pickTarget';
            cards.append(btn);
        });

        this.overlayTarget.replaceChildren(title, cards, this.#button('power_cancel', 'Pomiń (bez efektu)', 'ghost'));
    }

    #renderSeen() {
        const title = document.createElement('p');
        title.className = 'dutch-overlay-title';
        title.textContent = `Podejrzana karta — ${this.seen.ownerName}`;

        const card = create(this.seen.card);
        card.classList.add('dutch-seen-card');

        this.overlayTarget.replaceChildren(title, card);
    }

    #renderTurn() {
        const state = this.m.state;

        if ('playing' === state || 'final' === state) {
            this.turnTarget.textContent = this.m.myTurn
                ? 'Twoja tura'
                : `Tura: ${this.#name(this.m.turn)}`;
            this.turnTarget.classList.toggle('is-mine', Boolean(this.m.myTurn));
        } else {
            this.turnTarget.textContent = '';
            this.turnTarget.classList.remove('is-mine');
        }
    }

    #renderDiscard() {
        const top = this.m.discardTop;
        const show = Boolean(top) && ['playing', 'final'].includes(this.m.state);
        this.discardTarget.hidden = !show;

        if (show) {
            this.discardTarget.textContent = `Stos: ${top.label}`;
            this.discardTarget.classList.toggle('red', Boolean(top.red));
        }
    }

    /** Siatka podglądu budowana raz - żeby event „peeked” nie przebudował jej pod ręką. */
    #ensureBuilt() {
        if ('peek' === this.builtFor) {
            return;
        }

        this.builtFor = 'peek';
        this.handTarget.replaceChildren(...this.#slots().map((slot) => {
            const cell = this.#cardShell(slot);
            cell.dataset.action = 'click->dutch-player#peekTap';
            cell.classList.add('is-peekable');

            // Karta odkryta przed wejściem na ekran (odświeżenie w fazie podglądu)
            // ma już wartość - pokaż ją odkrytą.
            if (this.cache[slot] && this.opened.has(slot)) {
                paint(cell, this.cache[slot]);
            }

            return cell;
        }));

        // Rozdanie: karty wpadają z góry, jedna po drugiej.
        if (this.animateDeal && !this.#reduced()) {
            this.animateDeal = false;
            gsap.from(this.handTarget.children, {
                duration: 0.6,
                y: () => -window.innerHeight * 0.7,
                opacity: 0,
                rotation: () => gsap.utils.random(-14, 14),
                stagger: 0.12,
                ease: 'power2.out',
            });
        }
    }

    #renderHand() {
        const state = this.m.state;
        const play = ['playing', 'final'].includes(state);
        const revealed = 'reveal' === state ? (this.m.revealed?.[this.myId] ?? null) : null;
        const canSwap = play && this.m.myTurn && Boolean(this.m.drawn);
        // Poza podmianą każda własna karta służy do dorzucenia - gdy na stosie
        // coś leży, dotknięcie próbuje ją tam wrzucić (refleks).
        const canSnap = play && !canSwap && Boolean(this.m.discardTop);

        this.handTarget.replaceChildren(...this.#handShape().map((present, index) => {
            const cell = this.#cardShell(String(index));

            // Puste pole po dorzuconej karcie - luka, w którą nie da się kliknąć.
            if (!present) {
                cell.classList.remove('dutch-down');
                cell.classList.add('dutch-empty');

                return cell;
            }

            if (revealed) {
                const card = revealed[index] ?? null;

                if (card) {
                    paint(cell, card);
                } else {
                    cell.classList.remove('dutch-down');
                    cell.classList.add('dutch-empty');
                }

                return cell;
            }

            if (canSwap) {
                cell.classList.add('is-target');
                cell.dataset.action = 'click->dutch-player#swap';
            } else if (canSnap) {
                cell.classList.add('is-snap');
                cell.dataset.action = 'click->dutch-player#snap';
            }

            return cell;
        }));
    }

    /** Kształt własnej ręki: lista bool (true = karta, false = luka). */
    #handShape() {
        return Array.isArray(this.m.myHand) && this.m.myHand.length
            ? this.m.myHand
            : Array.from({ length: this.m.handSize ?? 4 }, () => true);
    }

    #cardShell(slot) {
        const cell = document.createElement('button');
        cell.type = 'button';
        cell.className = 'dutch-card dutch-down';
        cell.dataset.slot = slot;

        return cell;
    }

    #showPeek(slot) {
        const cell = this.handTarget.querySelector(`[data-slot="${slot}"]`);

        if (cell && this.cache[slot]) {
            paint(cell, this.cache[slot]);

            // Podniesienie karty jak przy prawdziwym stole - obrót awersem do góry.
            if (!this.#reduced()) {
                gsap.from(cell, { duration: 0.55, rotationY: 90, transformPerspective: 500, ease: 'power2.out' });
            }
        }
    }

    #hidePeek(slot) {
        const cell = this.handTarget.querySelector(`[data-slot="${slot}"]`);

        if (cell) {
            blank(cell);
        }
    }

    #renderDrawn() {
        const show = ['playing', 'final'].includes(this.m.state) && this.m.myTurn && Boolean(this.m.drawn);
        this.drawnRowTarget.hidden = !show;

        if (!show) {
            this.drawnRowTarget.replaceChildren();

            return;
        }

        const label = document.createElement('span');
        label.className = 'dutch-drawn-label';
        label.textContent = 'Dobrana karta';

        const card = create(this.m.drawn);
        card.classList.add('dutch-drawn-card');

        // Punkty przy dobranej: od razu widać, czy warto ją brać, czy wyrzucić.
        const pts = document.createElement('span');
        pts.className = 'dutch-drawn-pts';
        pts.textContent = `${this.m.drawn.value} pkt`;

        this.drawnRowTarget.replaceChildren(label, card, pts);

        // Świeżo dobrana karta wskakuje z obrotem - raz, nie przy każdym renderze.
        if (this.animateDrawn && !this.#reduced()) {
            this.animateDrawn = false;
            gsap.from(card, { duration: 0.6, scale: 0.4, rotation: -18, opacity: 0, ease: 'back.out(1.6)' });
        }
    }

    #renderControls() {
        // W trakcie wskazywania karty do podejrzenia akcją jest sama nakładka.
        if (this.picking) {
            this.controlsTarget.replaceChildren();

            return;
        }

        const nodes = [];
        const state = this.m.state;
        const playing = ['playing', 'final'].includes(state);

        if (playing && this.m.myTurn && !this.m.drawn) {
            nodes.push(this.#button('draw', 'Dobierz kartę', 'primary'));

            // „Dutch” wołasz na początku swojej tury i tylko przed rundą końcową.
            if ('playing' === state) {
                nodes.push(this.#button('call', 'Zawołaj „Dutch”', 'call'));
            }
        }

        if (playing && this.m.myTurn && this.m.drawn) {
            // Dama i walet dają dodatkową opcję zamiast podmiany.
            const power = this.m.drawnPower;

            if ('peek_own' === power) {
                nodes.push(this.#button('power', '👁 Podejrzyj swoją (dama)', 'call'));
            } else if ('swap' === power) {
                nodes.push(this.#button('power', '🔄 Zamień karty (walet)', 'call'));
            }

            nodes.push(this.#button('discard', power ? 'Wyrzuć bez akcji' : 'Wyrzuć dobraną', ''));
        }

        this.controlsTarget.replaceChildren(...nodes);
    }

    #button(action, label, variant) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `btn btn-lg dutch-btn${variant ? ` dutch-btn-${variant}` : ''}`;
        button.textContent = label;
        button.dataset.action = {
            draw: 'dutch-player#draw',
            discard: 'dutch-player#discardDrawn',
            call: 'dutch-player#callDutch',
            power: 'dutch-player#usePower',
            power_cancel: 'dutch-player#cancelPower',
        }[action];

        return button;
    }

    /**
     * Rezygnacja z mocy bez wykonania jej efektu - wyjście awaryjne, gdy
     * wskazanie celu jest niewykonalne (np. wskazany gracz w międzyczasie
     * wysnapował ostatnią kartę). Karta i tak już poszła na stos w usePower;
     * to tylko pomija efekt i oddaje turę dalej.
     */
    async cancelPower() {
        this.pickFirst = null;
        await this.#send('power_cancel');
    }

    #note() {
        const state = this.m.state;

        if (this.snapNote) {
            return this.snapNote;
        }
        if (this.picking) {
            if ('swap' === this.pickKind) {
                return this.pickFirst
                    ? 'Wskaż drugiego gracza — zamienię im losowo po karcie.'
                    : 'Walet: wskaż dwóch graczy do zamiany.';
            }

            return 'Wskaż swoją kartę, żeby ją podejrzeć.';
        }
        if (this.seen) {
            return 'Zapamiętaj! Ta karta zaraz zniknie.';
        }
        if ('idle' === state) {
            return 'Czekaj na rozdanie kart.';
        }
        if ('peek' === state) {
            if (this.peekLocked) {
                return 'Podgląd zamknięty — dalej grasz z pamięci.';
            }

            if (this.peekDeadline) {
                const left = Math.max(0, Math.ceil((this.peekDeadline - Date.now()) / 1000));

                return `Zapamiętaj! Karty zakryją się za ${left} s.`;
            }

            return `Kliknij kartę, żeby ją odkryć (możesz ${this.m.peekLimit ?? 2}). Od pierwszej masz 10 s.`;
        }
        if ('playing' === state || 'final' === state) {
            // Poza podmianą dotknięcie karty = dorzucenie na stos, jeśli pasuje.
            const snap = this.m.discardTop ? ' Masz taką samą figurę? Dotknij karty, żeby dorzucić na stos.' : '';

            if (!this.m.myTurn) {
                return ('final' === state ? 'Runda końcowa — zaraz koniec.' : 'Czekaj na swoją turę.') + snap;
            }

            if (!this.m.drawn) {
                return 'Dobierz kartę. Masz najniżej? Zawołaj „Dutch”.' + snap;
            }

            return this.m.drawnPower
                ? 'Ta karta ma moc! Podejrzyj nią, podmień (dotknij) albo wyrzuć.'
                : 'Podmień ją za jedną ze swoich (dotknij karty) albo wyrzuć dobraną.';
        }
        if ('reveal' === state) {
            return this.#revealNote();
        }

        return '';
    }

    #revealNote() {
        const winners = this.m.scores?.winners ?? [];
        const mine = this.m.scores?.sums?.[this.myId];
        const won = winners.includes(this.myId);
        const suffix = undefined === mine ? '' : ` Twój wynik: ${mine}.`;

        return (won ? 'Wygrywasz rundę!' : 'Runda skończona.') + suffix;
    }

    #slots() {
        return this.#handShape().map((_, index) => String(index));
    }

    #name(id) {
        return (this.m.players ?? []).find((player) => player.id === id)?.name ?? 'gracz';
    }

    async #send(action, payload = {}) {
        const response = await fetch(this.actionUrlValue, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ action, payload }),
        });

        if (!response.ok) {
            const problem = await response.json().catch(() => ({}));
            this.noteTarget.textContent = problem.detail ?? 'Nie udało się wykonać akcji.';
        }
    }
}
