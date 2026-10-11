import { Controller } from '@hotwired/stimulus';
import { sfx } from '../audio/sfx.js';

/** Sekund pełnej puli, zanim zacznie topnieć - lustro KalamburyRysowaneGame::GRACE_SECONDS. */
const GRACE_SECONDS = 10;

/**
 * Liczy tę samą wartość, co serwer w chwili "zgadnięto" - wyświetlana tu
 * liczba jest czystą zapowiedzią, prawda zapada dopiero na serwerze
 * (KalamburyRysowaneGame::pointsFor()). Obie kopie muszą się zgadzać, inaczej
 * malejący licznik na ekranie kłamałby o tym, ile faktycznie polecą punktów.
 */
function pointsFor(basePoints, secondsTotal, remainingSeconds) {
    const decayWindow = secondsTotal - GRACE_SECONDS;

    if (decayWindow <= 0 || remainingSeconds >= decayWindow) {
        return basePoints;
    }

    const fraction = Math.max(0, remainingSeconds) / decayWindow;

    return Math.max(1, Math.round(basePoints * fraction));
}

/**
 * Panel kalambur rysowanych. Jeden szablon, dwie role - ten sam ekran hosta
 * (ten sam token, ten sam `/host/{code}`) potrafi być albo projektorem
 * (widzi cała sala), albo tabletem do rysowania (trzyma go rysujący).
 *
 * Wybór roli żyje w localStorage TEGO urządzenia - dwa fizyczne ekrany
 * logujące się tym samym tokenem hosta (patrz JoinLink::hostTransferUrl)
 * pamiętają swoją rolę niezależnie, bez żadnego stanu po stronie serwera.
 *
 * Rysunek NIE jest stanem gry: leci osobnym, nieblokującym kanałem
 * (game_live) i renderuje się WYŁĄCZNIE z odebranych zdarzeń, nigdy z lokalnych
 * zdarzeń wskaźnika wprost - jedna ścieżka rysowania dla obu ról, bez
 * podwójnego rysowania tej samej kreski na urządzeniu, które ją wysłało.
 */
export default class extends Controller {
    static targets = ['roleGate', 'categoryPicker', 'categorySelect', 'secondsSelect',
        'progress', 'turn', 'answer', 'event', 'choices', 'canvasWrap', 'canvas', 'clearBtn',
        'controls', 'hint', 'foot', 'clock', 'clockValue', 'pointsBadge', 'pointsValue',
        'secretWordCard', 'secretWord', 'wordToggle', 'roleSwitch', 'focusToggle'];
    static values = { actionUrl: String, viewUrl: String, liveUrl: String, roomCode: String };

    connect() {
        this.role = null;
        this.offset = 0;
        this.lastPoint = null;
        // Hasło jest domyślnie schowane w trakcie rysowania - rysujący sam
        // je przed chwilą wybrał, "👁 Pokaż hasło" to tylko awaryjne
        // przypomnienie, nie coś widoczne przez cały czas (patrz #render()).
        this.wordVisible = false;
        this.roleKey = `rys-role-${this.roomCodeValue}`;
        this.teams = new Map(
            [...document.querySelectorAll('[data-team-id]')].map((tile) => [
                tile.dataset.teamId,
                tile.querySelector('.team-name')?.textContent?.trim() ?? 'drużyna',
            ]),
        );

        this.onEvent = (event) => this.#onServerEvent(event.detail);
        window.addEventListener('gierki:event', this.onEvent);

        this.onResize = () => this.#resizeCanvas();
        window.addEventListener('resize', this.onResize);

        // Przeglądarka sama wychodzi z pełnego ekranu na Esc/geście systemu -
        // bez tego nasza klasa `rys-focus` zostałaby włączona, a chrom
        // (szyna/HUD) już by nie wrócił, mimo że fizycznie nie ma już pełnego
        // ekranu. Ten sam wzorzec co `onFullscreen` w room_board_controller.js.
        this.onFullscreenChange = () => {
            if (!document.fullscreenElement) {
                this.#applyFocus(false);
            }
        };
        document.addEventListener('fullscreenchange', this.onFullscreenChange);

        // Link/QR transferu roli (host.html.twig: "Na TABLECIE zeskanuj ten
        // kod") niesie intencję wprost w adresie (`?role=tablet`, doklejone
        // przez HostController po zdjęciu tokenu - patrz JoinLink::hostTransferUrl) -
        // kto go zeskanował, z definicji chce TEN ekran, więc bramka wyboru
        // byłaby zbędnym, mylącym krokiem ("czemu znowu pyta, przecież
        // zeskanowałem kod DO tabletu"). Czyścimy adres od razu, żeby
        // ewentualne odświeżenie nie musiało już o tym pamiętać - rola i tak
        // przetrwa dalej w localStorage (patrz #setRole).
        const linkedRole = new URLSearchParams(window.location.search).get('role');

        if ('tablet' === linkedRole || 'projector' === linkedRole) {
            window.history.replaceState(null, '', window.location.pathname);
            this.#setRole(linkedRole);

            return;
        }

        let savedRole = null;

        try {
            savedRole = window.localStorage.getItem(this.roleKey);
        } catch {
            // Prywatna karta albo zablokowany storage - rola po prostu nie
            // przetrwa odświeżenia, ekran po prostu wraca do wyboru roli.
        }

        if (savedRole) {
            this.#applyRole(savedRole);
        }

        this.refresh();
    }

    disconnect() {
        window.removeEventListener('gierki:event', this.onEvent);
        window.removeEventListener('resize', this.onResize);
        document.removeEventListener('fullscreenchange', this.onFullscreenChange);
        clearInterval(this.ticker);
    }

    /**
     * "Rysuj na całym ekranie" - chowa szynę projektora i pasek nad płótnem
     * na tablecie (kategoria/tura/hasło/zegar/punkty), zostaje sam rysunek
     * i sterowanie potrzebne DO GRY (wybór trudności, wskazanie kto zgadł,
     * "Wyczyść") - te siedzą w .game-main, nie w chowanym HUD/szynie, więc
     * nie znikają.
     *
     * To tylko POŁOWA chromu ekranu hosta - pasek u góry (Zmień grę/Reset...)
     * i panel wyników po lewej (.tv-bar/.tv-teams) żyją POZA .rys-panel,
     * w kontrolerze room-board (patrz host/screen.html.twig). Zamiast wołać
     * requestFullscreen tu DRUGI raz (dwa wywołania nałożyłyby się i psuły
     * stan `tv-focus` po stronie room-board), klikamy jego WŁASNY przycisk
     * "Pełny ekran" - ten sam gest, jaki zrobiłby host ręcznie, więc jedno
     * kliknięcie tej gry naprawdę oddaje CAŁY ekran, nie tylko wnętrze
     * płótna. Degraduje się bezpiecznie, gdyby przycisk kiedyś zniknął
     * z DOM-u (np. inny wariant ekranu hosta) - wtedy prosimy sami.
     */
    toggleFocus() {
        this.#applyFocus(!this.element.classList.contains('rys-focus'));
    }

    #applyFocus(on) {
        if (on === this.element.classList.contains('rys-focus')) {
            return;
        }

        this.element.classList.toggle('rys-focus', on);

        this.focusToggleTargets.forEach((el) => {
            el.textContent = on ? '🗗' : '⛶';
            el.title = on ? 'Zamknij pełny ekran' : 'Rysuj na całym ekranie';
            el.setAttribute('aria-label', el.title);
        });

        const tvToggle = document.querySelector('[data-action="room-board#toggleFocus"]');
        const tvFocused = Boolean(document.querySelector('.tv')?.classList.contains('tv-focus'));

        if (tvToggle) {
            if (tvFocused !== on) {
                tvToggle.click();
            }
        } else if (on) {
            document.documentElement.requestFullscreen?.().catch(() => {});
        } else if (document.fullscreenElement) {
            document.exitFullscreen?.().catch(() => {});
        }

        // Chowanie szyny/HUD-a zmienia rozmiar kontenera płótna - bez
        // przeliczenia canvas.width/height zostałby rozmyty (stara
        // rozdzielczość rozciągnięta na nowy, większy CSS-owy rozmiar).
        requestAnimationFrame(() => this.#resizeCanvas());
    }

    chooseProjector() {
        this.#setRole('projector');
    }

    chooseTablet() {
        this.#setRole('tablet');
    }

    switchRole() {
        try {
            window.localStorage.removeItem(this.roleKey);
        } catch {
            // jak wyżej - brak trwałości nie jest tu błędem
        }

        this.role = null;
        delete this.element.dataset.role;
        this.roleGateTarget.hidden = false;
    }

    async refresh() {
        // Ignorujemy odpowiedź, jeśli w międzyczasie wystartował nowszy refresh()
        // - ten sam wzorzec co w kalambury_host_controller.js.
        const requestId = (this.refreshSeq = (this.refreshSeq ?? 0) + 1);
        const response = await fetch(this.viewUrlValue, { headers: { Accept: 'application/json' } });

        if (requestId !== this.refreshSeq) {
            return;
        }

        if (response.ok) {
            this.#render(await response.json());
        }
    }

    async pickCategory() {
        await this.#send('pick_category', {
            category: this.categorySelectTarget.value,
            seconds: Number(this.secondsSelectTarget.value),
        });
    }

    async choose(event) {
        await this.#send('choose', { level: event.currentTarget.dataset.level });
    }

    async guessedBy(event) {
        await this.#send('guessed', { teamId: event.currentTarget.dataset.teamId });
    }

    async act(event) {
        const button = event.currentTarget;

        if (button.disabled) {
            return;
        }

        button.disabled = true;

        try {
            await this.#send(button.dataset.rysAction, {});
        } finally {
            button.disabled = false;
        }
    }

    clearCanvas() {
        this.#sendLive('stroke_clear', 0, 0);
    }

    /** "👁 Pokaż/ukryj hasło" - jedyny komunikat, jaki rysujący może przywołać. */
    toggleWord() {
        this.wordVisible = !this.wordVisible;
        this.secretWordCardTarget.hidden = !this.wordVisible;
        this.#renderWordToggle();
    }

    onPointerDown(event) {
        if ('tablet' !== this.role || !this.view || 'drawing' !== this.view.state) {
            return;
        }

        event.preventDefault();
        this.drawingActive = true;
        this.canvasTarget.setPointerCapture(event.pointerId);

        const [x, y] = this.#relative(event);
        this.#sendLive('stroke_start', x, y);
    }

    onPointerMove(event) {
        if (!this.drawingActive) {
            return;
        }

        // Rysowanie lokalne leci wyłącznie z odebranego zdarzenia (patrz
        // #applyStroke), więc tu tylko dławimy CZĘSTOTLIWOŚĆ WYSYŁKI - sama
        // kreska i tak wraca przez SSE zanim ktokolwiek zdąży to zauważyć w LAN.
        const now = performance.now();

        if (now - (this.lastSend ?? 0) < 35) {
            return;
        }

        this.lastSend = now;
        const [x, y] = this.#relative(event);
        this.#sendLive('stroke_point', x, y);
    }

    onPointerUp() {
        if (!this.drawingActive) {
            return;
        }

        this.drawingActive = false;
        this.#sendLive('stroke_end', 0, 0);
    }

    #setRole(role) {
        try {
            window.localStorage.setItem(this.roleKey, role);
        } catch {
            // jak wyżej - działa też bez trwałości, tylko trzeba będzie
            // wybrać rolę ponownie po odświeżeniu
        }

        this.#applyRole(role);
    }

    #applyRole(role) {
        this.role = role;
        this.roleGateTarget.hidden = true;
        // CSS ([data-role="tablet"] .rys-only-projector itd., app.css) chowa
        // to, co nie należy do tej roli - stąd atrybut na korzeniu kontrolera,
        // nie na jakimś wewnętrznym opakowaniu (.game-panel ma własną,
        // sztywną siatkę grid-template-areas, którą .rys-panel nadpisuje na
        // jedną kolumnę - main/foot muszą zostać jego bezpośrednimi dziećmi,
        // patrz host.html.twig).
        this.element.dataset.role = role;

        this.refresh();
    }

    #onServerEvent(event) {
        if (['stroke_start', 'stroke_point', 'stroke_end', 'stroke_clear'].includes(event.type)) {
            this.#applyStroke(event);

            return;
        }

        if ('clear_canvas' === event.type) {
            this.#clearCanvasLocal();

            return;
        }

        if ('round_over' === event.type) {
            this.#flash('guessed' === event.reason
                ? `${this.#teamName(event.teamId)} odgaduje! +${event.points ?? '?'} pkt`
                : 'Czas minął');

            if ('guessed' === event.reason) {
                sfx.play('correct');
                sfx.play('applause', { delay: 0.55 });
            }
        }

        if ('choices_ready' === event.type) {
            sfx.play('whoosh');
        }

        if (['phase', 'timer', 'choices_ready', 'choices_skipped', 'drawing_started', 'round_over',
            'turn', 'category_picked', 'team_joined', 'reset', 'resync'].includes(event.type)) {
            if ('team_joined' === event.type) {
                this.teams.set(event.team.id, event.team.name);
            }

            this.refresh();
        }
    }

    #render(view) {
        this.view = view;
        // Stan gry na panelu - motyw (gierkownia.css) robi z oczekiwania na
        // wybór hasła osobną scenę na projektorze, zamiast pustego płótna.
        this.element.dataset.state = view.state ?? '';

        if (!this.role) {
            return;
        }

        this.categoryPickerTarget.hidden = Boolean(view.category);

        if (!view.category) {
            this.#fillCategories(view.categories ?? []);
        }

        this.progressTarget.textContent = view.category
            ? `${view.categoryName} — hasło ${view.cardNumber} z ${view.cardTotal}`
            : '';

        this.turnTarget.textContent = view.turn ? `Rysuje: ${this.#teamName(view.turn)}` : '';

        // Hasło dopiero po rundzie na projektorze - w trakcie patrzą na nie
        // zgadujący. Na tablecie widać je na żądanie, patrz #renderWordToggle().
        this.answerTarget.textContent = ['guessed', 'timeout'].includes(view.state) ? (view.word ?? '') : '';

        // Tablet w trakcie rysowania ma pokazywać TYLKO płótno - żadnego
        // zegara, punktów, przełącznika roli ani podpowiedzi (życzenie
        // użytkownika). Jedyny wyjątek to hasło, i to na żądanie, nie stale.
        const tabletDrawing = 'tablet' === this.role && 'drawing' === view.state;

        if (this.hasSecretWordTarget) {
            const drawing = 'drawing' === view.state;

            if (!drawing) {
                // Nowa runda ma znów startować schowana - to, że ktoś
                // podejrzał poprzednie hasło, nie powinno zdradzać kolejnego.
                this.wordVisible = false;
            }

            this.wordToggleTarget.hidden = !drawing;
            this.secretWordCardTarget.hidden = !drawing || !this.wordVisible;
            this.secretWordTarget.textContent = drawing ? (view.word ?? '') : '';
            this.#renderWordToggle();
        }

        this.roleSwitchTargets.forEach((el) => { el.hidden = tabletDrawing; });
        this.footTarget.hidden = tabletDrawing;

        this.choicesTarget.hidden = 'dealt' !== view.state;

        if ('dealt' === view.state) {
            this.#renderChoices(view.choices ?? {});
        }

        const showCanvas = ['drawing', 'guessed', 'timeout'].includes(view.state);
        const canvasWasHidden = this.canvasWrapTarget.hidden;
        this.canvasWrapTarget.hidden = !showCanvas;

        if (showCanvas && canvasWasHidden) {
            this.#resizeCanvas();
        }

        this.clearBtnTarget.hidden = 'drawing' !== view.state;

        this.#syncClock(view, tabletDrawing);
        this.#renderControls(view, tabletDrawing);
    }

    #renderWordToggle() {
        this.wordToggleTarget.textContent = this.wordVisible ? '🙈 Ukryj hasło' : '👁 Pokaż hasło';
    }

    #renderChoices(choices) {
        const order = ['latwy', 'sredni', 'trudny'];
        const labels = { latwy: '🟢 Łatwe', sredni: '🟡 Średnie', trudny: '🔴 Trudne' };

        const nodes = order.filter((level) => choices[level]).map((level) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `btn btn-lg rys-choice rys-choice-${level}`;
            button.dataset.level = level;
            button.dataset.action = 'rysowanki-host#choose';

            const label = document.createElement('span');
            label.className = 'rys-choice-label';
            label.textContent = labels[level];

            const word = document.createElement('span');
            word.className = 'rys-choice-word';
            word.textContent = choices[level].phrase;

            button.append(label, word);

            return button;
        });

        this.choicesTarget.replaceChildren(...nodes);
    }

    #renderControls(view, tabletDrawing) {
        const nodes = 'tablet' === this.role ? this.#tabletButtons(view) : this.#projectorButtons(view);
        this.controlsTarget.replaceChildren(...nodes);
        this.hintTarget.textContent = tabletDrawing ? '' : this.#hintFor(view);
    }

    #projectorButtons(view) {
        const buttons = {
            idle: view.category ? [['deal', 'Losuj 3 hasła', 'primary']] : [],
            dealt: [],
            drawing: [],
            guessed: [['next_round', 'Następna drużyna', 'primary']],
            timeout: [['next_round', 'Następna drużyna', 'primary']],
        }[view.state] ?? [];

        return buttons.map(([action, label, variant]) => this.#actionButton(action, label, variant));
    }

    #tabletButtons(view) {
        if ('dealt' === view.state) {
            return [this.#actionButton('skip', 'Inne hasła', '')];
        }

        // Kto zgadł, wskazuje TABLET, nie projektor - hasło zna wyłącznie
        // rysujący (patrz .rys-only-tablet w app.css), więc tylko on może
        // uczciwie ocenić, czy okrzyk ze stołu faktycznie trafił - zgłoszenie
        // użytkownika ("zwycięzcę wybiera osoba rysująca - bo ona zna hasło").
        if ('drawing' === view.state) {
            return this.#teamGuessButtons(view);
        }

        return [];
    }

    /**
     * Przyciski "✓ Drużyna" - punkt dostają zgadujący i rysujący, więc
     * wskazanie musi być jawne (wywołuje akcję 'guessed' z teamId).
     */
    #teamGuessButtons(view) {
        return (view.teams ?? [])
            .filter((team) => team.id !== view.turn)
            .map((team) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'btn btn-lg';
                button.textContent = `✓ ${team.name}`;
                button.dataset.teamId = team.id;
                button.dataset.action = 'rysowanki-host#guessedBy';

                return button;
            });
    }

    #actionButton(action, label, variant) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `btn btn-lg${'primary' === variant ? ' btn-primary' : ''}`;
        button.textContent = label;
        button.dataset.rysAction = action;
        button.dataset.action = 'rysowanki-host#act';

        return button;
    }

    #hintFor(view) {
        const projectorHints = {
            idle: view.category ? 'Wylosuj 3 hasła dla rysującej drużyny.' : 'Wybierz kategorię i czas rundy.',
            dealt: 'Rysujący wybiera hasło na tablecie…',
            // Hasło zna wyłącznie tablet - stąd projektor tylko ogląda, ocenę
            // podejmuje rysujący na swoim ekranie.
            drawing: 'Rysujący ocenia na tablecie, kto zgadł.',
            guessed: 'Odgadnięte.',
            timeout: 'Czas minął — nikt nie odgadł.',
        };
        const tabletHints = {
            // Kategoria bywa już wybrana - wtedy czekamy na losowanie haseł, nie na wybór.
            idle: view.category ? 'Czekaj, aż host wylosuje hasła.' : 'Czekaj, aż host wybierze kategorię.',
            dealt: 'Wybierz jedno z trzech haseł.',
            drawing: 'Rysuj! Kliknij drużynę, która zgadła.',
            guessed: 'Odgadnięte!',
            timeout: 'Czas minął.',
        };

        return ('tablet' === this.role ? tabletHints : projectorHints)[view.state] ?? '';
    }

    /**
     * Zegar i odznaka punktów istnieją DWA razy w DOM - w szynie projektora
     * i w pasku tabletu (patrz host.html.twig) - stąd `Targets` w liczbie
     * mnogiej: Stimulus zwraca WSZYSTKIE dopasowania pod tą nazwą, więc obie
     * kopie aktualizują się razem, z jednego źródła danych.
     *
     * `tabletDrawing` chowa WYŁĄCZNIE widok na tablecie (życzenie użytkownika:
     * "tylko ekran do rysowania") - projektor dalej pokazuje swoją kopię bez
     * zmian. Sam odliczający interwał (`tick`) MUSI jednak lecieć dalej mimo
     * ukrycia: to on zgłasza `timeout` serwerowi, gdy czas minie, a tablet
     * bywa jedynym otwartym ekranem, gdy projektor akurat nie odświeżył widoku.
     */
    #syncClock(view, tabletDrawing) {
        clearInterval(this.ticker);

        if (!view.deadline || 'drawing' !== view.state) {
            this.clockTargets.forEach((el) => { el.hidden = true; });
            this.pointsBadgeTargets.forEach((el) => { el.hidden = true; });

            return;
        }

        this.offset = view.serverNow - Date.now();
        this.clockTargets.forEach((el) => { el.hidden = tabletDrawing; });
        this.pointsBadgeTargets.forEach((el) => { el.hidden = tabletDrawing; });

        const basePoints = view.basePoints ?? 0;
        const secondsTotal = view.seconds ?? 0;

        const tick = () => {
            const left = Math.max(0, view.deadline - (Date.now() + this.offset));
            const leftSeconds = Math.ceil(left / 1000);
            const state = left < 10_000 ? 'urgent' : 'running';
            const points = pointsFor(basePoints, secondsTotal, leftSeconds);

            this.clockValueTargets.forEach((el) => { el.textContent = leftSeconds; });
            this.clockTargets.forEach((el) => { el.dataset.state = state; });
            this.pointsValueTargets.forEach((el) => { el.textContent = points; });

            if (0 === left) {
                clearInterval(this.ticker);
                // O upływie czasu i tak rozstrzyga serwer - my tylko meldujemy.
                this.#send('timeout');
            }
        };

        this.ticker = setInterval(tick, 250);
        tick();
    }

    #applyStroke(event) {
        if (!this.hasCanvasTarget || this.canvasWrapTarget.hidden) {
            return;
        }

        if ('stroke_clear' === event.type) {
            this.#clearCanvasLocal();

            return;
        }

        if ('stroke_end' === event.type) {
            this.lastPoint = null;

            return;
        }

        const ctx = this.canvasTarget.getContext('2d');
        const [px, py] = this.#toPixel(event.x ?? 0, event.y ?? 0);

        if ('stroke_start' === event.type || !this.lastPoint) {
            ctx.beginPath();
            ctx.arc(px, py, ctx.lineWidth / 2, 0, Math.PI * 2);
            ctx.fillStyle = ctx.strokeStyle;
            ctx.fill();
        } else {
            ctx.beginPath();
            ctx.moveTo(...this.lastPoint);
            ctx.lineTo(px, py);
            ctx.stroke();
        }

        this.lastPoint = [px, py];
    }

    #clearCanvasLocal() {
        if (!this.hasCanvasTarget) {
            return;
        }

        const canvas = this.canvasTarget;
        canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height);
        this.lastPoint = null;
    }

    /**
     * Rozdzielczość płótna liczona na nowo tylko przy przejściu z ukrycia do
     * widoczności i przy zmianie rozmiaru okna - ustawienie canvas.width
     * zawsze czyści płótno, więc robienie tego przy KAŻDYM renderze
     * (odświeżenie leci po prawie każdym evencie) wymazywałoby rysunek
     * w trakcie rundy.
     */
    #resizeCanvas() {
        if (!this.hasCanvasTarget || this.canvasWrapTarget.hidden) {
            return;
        }

        const canvas = this.canvasTarget;
        const rect = canvas.getBoundingClientRect();

        if (0 === rect.width || 0 === rect.height) {
            return;
        }

        const ratio = window.devicePixelRatio || 1;
        canvas.width = Math.max(1, Math.round(rect.width * ratio));
        canvas.height = Math.max(1, Math.round(rect.height * ratio));

        const ctx = canvas.getContext('2d');
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = '#1a1d29';
        ctx.lineWidth = Math.max(3, rect.width * 0.008) * ratio;
        this.lastPoint = null;
    }

    #toPixel(x, y) {
        const canvas = this.canvasTarget;

        return [x * canvas.width, y * canvas.height];
    }

    #relative(event) {
        const rect = this.canvasTarget.getBoundingClientRect();
        const x = Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width));
        const y = Math.min(1, Math.max(0, (event.clientY - rect.top) / rect.height));

        return [x, y];
    }

    async #sendLive(type, x, y) {
        try {
            await fetch(this.liveUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ type, x, y }),
            });
        } catch {
            // Kreska to dekoracja - zgubiony punkt nie psuje rundy, więc nie
            // ma tu czego ponawiać.
        }
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
