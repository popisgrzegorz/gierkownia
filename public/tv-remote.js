/*
 * Nasze Gierki — obsługa pilota telewizora (Samsung Tizen i inne Smart TV).
 *
 * Wrzuć do strony (np. public/tv-remote.js) i dołącz w base.html.twig:
 *     <script src="/tv-remote.js" defer></script>
 *
 * Włącza się sam tylko na telewizorze (User-Agent: Tizen / SMART-TV / Web0S).
 * Do testów na komputerze: dopisz ?tv=1 do adresu (zapamiętuje się w karcie),
 * ?tv=0 wyłącza.
 *
 * Co robi:
 *  - strzałki przesuwają fokus między przyciskami, linkami i polami (nawigacja
 *    przestrzenna — w stronę, którą wskazuje strzałka),
 *  - OK/Enter klika (to robi sama przeglądarka),
 *  - w polu wyboru (select) ◀ ▶ zmieniają wartość, a ▲ ▼ wychodzą do następnego pola,
 *  - Wstecz: zamyka klawiaturę w polu tekstowym, a poza nim cofa stronę
 *    (dwukrotne naciśnięcie, żeby nie wyjść z pokoju przez przypadek),
 *  - szybkie podwójne Wstecz (≤ 0,6 s) zamyka aplikację na telewizorze,
 *  - OK „klika" też elementy, które nie są przyciskami (np. z data-action Stimulusa),
 *  - gdy Turbo / Mercure podmieni zaznaczony element, fokus sam wraca na stronę,
 *  - wyraźna ramka fokusu widoczna z kanapy.
 *
 * Element, który ma dostać fokus po wejściu na stronę: data-tv-focus.
 * Kontener, w którym gra sama obsługuje strzałki: data-tv-keys="native".
 * Element, którego pilot ma nie zaznaczać: data-tv-skip.
 *
 * Celowo ES5 bez modułów — działa też na starszych silnikach telewizorów.
 */
(function () {
    'use strict';

    // ---------- Czy jesteśmy na telewizorze? ----------

    var FLAG = 'naszeGierki.tvMode';
    var query = window.location.search;
    try {
        if (/[?&]tv=1\b/.test(query)) sessionStorage.setItem(FLAG, '1');
        if (/[?&]tv=0\b/.test(query)) sessionStorage.removeItem(FLAG);
    } catch (e) { /* brak sessionStorage */ }

    var forced = false;
    try { forced = sessionStorage.getItem(FLAG) === '1'; } catch (e) { /* j.w. */ }

    var isTv = forced || /Tizen|SMART-TV|SmartTV|Web0S|webOS|HbbTV/i.test(navigator.userAgent);
    if (!isTv || window.__naszeGierkiTv) return;
    window.__naszeGierkiTv = true;

    var KEY = {
        LEFT: 37, UP: 38, RIGHT: 39, DOWN: 40, ENTER: 13,
        BACK: 10009, ESC: 27, BACKSPACE: 8
    };
    var DIRS = {};
    DIRS[KEY.LEFT] = 'left';
    DIRS[KEY.UP] = 'up';
    DIRS[KEY.RIGHT] = 'right';
    DIRS[KEY.DOWN] = 'down';

    var BACK_CONFIRM_MS = 2000;
    var QUICK_BACK_MS = 600;
    var FOCUSABLE = [
        'a[href]', 'button', 'input:not([type="hidden"])', 'select', 'textarea', 'summary',
        '[tabindex]:not([tabindex="-1"])', '[contenteditable="true"]',
        // klikalne elementy, które nie są przyciskami
        '[role="button"]', '[onclick]', '[data-tv-focus]',
        '[data-action]:not(form):not(input):not(select):not(textarea):not(body):not(html):not(main):not(section)'
    ].join(',');

    // Elementy, które same reagują na Enter (resztę „klikamy" ręcznie)
    function isNativelyActivated(el) {
        var t = el.tagName;
        if (t === 'A' || t === 'BUTTON' || t === 'SELECT' || t === 'TEXTAREA' || t === 'SUMMARY') return true;
        if (t === 'INPUT') return true;
        return el.isContentEditable;
    }

    // ---------- Wygląd ----------

    var style = document.createElement('style');
    style.textContent = [
        'html.tv-mode *:focus {',
        '  outline: 6px solid #f5a623 !important;',
        '  outline-offset: 4px !important;',
        '  box-shadow: 0 0 0 12px rgba(245,166,35,.25) !important;',
        '}',
        'html.tv-mode, html.tv-mode * { cursor: none !important; }',
        '.tv-toast {',
        '  position: fixed; left: 50%; bottom: 64px; z-index: 2147483647;',
        '  transform: translateX(-50%);',
        '  padding: 20px 40px; border-radius: 16px;',
        '  background: rgba(11,13,23,.92); color: #fff;',
        '  font: 600 30px/1.3 system-ui, Arial, sans-serif;',
        '  border: 3px solid #f5a623; pointer-events: none;',
        '  opacity: 0; transition: opacity .2s;',
        '}',
        '.tv-toast.show { opacity: 1; }'
    ].join('\n');

    // Skrypt może ruszyć, zanim powstanie <html> — wtedy dokładamy styl później.
    function installStyles() {
        var root = document.documentElement;
        if (!root) return false;
        if (!/\btv-mode\b/.test(root.className)) root.className += ' tv-mode';
        if (!style.parentNode) (document.head || root).appendChild(style);
        return true;
    }
    if (!installStyles()) document.addEventListener('DOMContentLoaded', installStyles);

    // ---------- Klawisze Tizen ----------

    // Strzałki, OK i Wstecz działają bez rejestracji. Obiekt `tizen` bywa
    // niedostępny na stronach z internetu — wtedy po prostu go pomijamy.
    function exitApp() {
        try {
            tizen.application.getCurrentApplication().exit();
            return true;
        } catch (e) {
            return false;
        }
    }

    // ---------- Pomocnicze ----------

    function isVisible(el) {
        if (el.disabled || el.hasAttribute('data-tv-skip')) return false;
        if (el.closest && el.closest('[hidden],[inert],[aria-hidden="true"]')) return false;
        var rect = el.getBoundingClientRect();
        if (rect.width <= 0 || rect.height <= 0) return false;
        var cs = window.getComputedStyle(el);
        return cs.visibility !== 'hidden' && cs.display !== 'none';
    }

    function candidates() {
        var list = document.querySelectorAll(FOCUSABLE);
        var out = [];
        for (var i = 0; i < list.length; i++) {
            if (isVisible(list[i])) out.push(list[i]);
        }
        return out;
    }

    function isTextField(el) {
        if (!el) return false;
        if (el.isContentEditable || el.tagName === 'TEXTAREA') return true;
        if (el.tagName !== 'INPUT') return false;
        return /^(text|password|search|email|url|tel|number|)$/i.test(el.type || '');
    }

    function hasValidFocus() {
        var a = document.activeElement;
        return a && a !== document.body && a !== document.documentElement && isVisible(a);
    }

    function focusEl(el) {
        if (!el) return;
        // div/span z data-action nie przyjmują fokusu bez tabindex
        if (el.tabIndex < 0 && !el.hasAttribute('tabindex')) el.setAttribute('tabindex', '-1');
        el.focus();
        if (el.scrollIntoView) {
            try {
                el.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' });
            } catch (e) {
                el.scrollIntoView(false);
            }
        }
    }

    // Pierwszy fokus: jawnie wskazany, potem przycisk główny, potem cokolwiek
    // co nie jest polem tekstowym (żeby nie wyskakiwała od razu klawiatura).
    function focusInitial() {
        var preferred = document.querySelector(
            '[data-tv-focus], [autofocus], .btn-primary, button[type="submit"]'
        );
        if (preferred && isVisible(preferred)) return focusEl(preferred);
        var list = candidates();
        for (var i = 0; i < list.length; i++) {
            if (!isTextField(list[i])) return focusEl(list[i]);
        }
        if (list.length) focusEl(list[0]);
    }

    // ---------- Nawigacja przestrzenna ----------

    function center(r) {
        return { x: r.left + r.width / 2, y: r.top + r.height / 2 };
    }

    // Najpierw szukamy elementów leżących w całości za krawędzią bieżącego
    // (strict), a dopiero gdy takich nie ma — dowolnych, których środek jest
    // w danym kierunku. Inaczej „w dół" potrafi skoczyć do sąsiada z tego
    // samego wiersza, który jest o kilka pikseli niżej.
    var EDGE_TOLERANCE = 8;

    function findNext(from, dir) {
        return findNextPass(from, dir, true) || findNextPass(from, dir, false);
    }

    function findNextPass(from, dir, strict) {
        var fr = from.getBoundingClientRect();
        var fc = center(fr);
        var best = null;
        var bestScore = Infinity;
        var list = candidates();
        var t = EDGE_TOLERANCE;

        for (var i = 0; i < list.length; i++) {
            var el = list[i];
            if (el === from) continue;
            var r = el.getBoundingClientRect();
            var c = center(r);
            var main, cross, overlap;

            if (dir === 'right') {
                main = r.left - fr.right;
                cross = c.y - fc.y;
                overlap = r.top < fr.bottom && r.bottom > fr.top;
                if (strict ? r.left < fr.right - t : c.x <= fc.x + 1) continue;
            } else if (dir === 'left') {
                main = fr.left - r.right;
                cross = c.y - fc.y;
                overlap = r.top < fr.bottom && r.bottom > fr.top;
                if (strict ? r.right > fr.left + t : c.x >= fc.x - 1) continue;
            } else if (dir === 'down') {
                main = r.top - fr.bottom;
                cross = c.x - fc.x;
                overlap = r.left < fr.right && r.right > fr.left;
                if (strict ? r.top < fr.bottom - t : c.y <= fc.y + 1) continue;
            } else {
                main = fr.top - r.bottom;
                cross = c.x - fc.x;
                overlap = r.left < fr.right && r.right > fr.left;
                if (strict ? r.bottom > fr.top + t : c.y >= fc.y - 1) continue;
            }

            // Elementy w tym samym wierszu/kolumnie wygrywają z tymi na ukos.
            var score = Math.max(main, 0) + Math.abs(cross) * (overlap ? 0.5 : 3);
            if (score < bestScore) {
                bestScore = score;
                best = el;
            }
        }
        return best;
    }

    // ---------- Pole wyboru (select) ----------
    //
    // Na telewizorze rozwijana lista to pułapka: przeglądarka zabiera strzałki ▲▼ na zmianę
    // wartości i zaznaczenie nie ma jak opuścić pola. Dlatego wartość zmieniamy strzałkami
    // w bok, a ▲▼ zostawiamy nawigacji. Listy wielowierszowe (multiple / size > 1) zachowują
    // się jak dotąd — tam ▲▼ to naturalne przewijanie.

    function isDropdown(el) {
        return !!el && el.tagName === 'SELECT' && !el.multiple && (el.size || 1) <= 1;
    }

    function fire(el, type) {
        var ev;
        try {
            ev = new Event(type, { bubbles: true });
        } catch (e) {
            ev = document.createEvent('Event');
            ev.initEvent(type, true, false);
        }
        el.dispatchEvent(ev);
    }

    // Następna / poprzednia wartość (pomijając pozycje wyłączone). Strona dostaje
    // zdarzenia input i change, więc Stimulus czy zwykły onchange działają jak zwykle.
    function stepSelect(sel, delta) {
        var opts = sel.options || [];
        for (var n = sel.selectedIndex + delta; n >= 0 && n < opts.length; n += delta) {
            if (opts[n].disabled) continue;
            sel.selectedIndex = n;
            fire(sel, 'input');
            fire(sel, 'change');
            return true;
        }
        return false;                      // koniec listy — nic nie zmieniamy
    }

    function caretAtEdge(el, dir) {
        try {
            var len = (el.value || '').length;
            if (el.selectionStart !== el.selectionEnd) return false;
            return dir === 'left' ? el.selectionStart === 0 : el.selectionEnd === len;
        } catch (e) {
            return true; // type=number/email nie mają selectionStart
        }
    }

    // ---------- Wstecz ----------

    var toastEl = null;
    var toastTimer = null;
    var lastBack = 0;

    function toast(text) {
        if (!toastEl) {
            toastEl = document.createElement('div');
            toastEl.className = 'tv-toast';
        }
        if (!toastEl.parentNode) document.body.appendChild(toastEl); // Turbo podmienia <body>
        toastEl.textContent = text;
        toastEl.className = 'tv-toast show';
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.className = 'tv-toast'; }, BACK_CONFIRM_MS);
    }

    var lastQuickBack = 0;

    function onBack() {
        var now0 = Date.now();
        var quick = now0 - lastQuickBack <= QUICK_BACK_MS;
        lastQuickBack = now0;
        var active = document.activeElement;
        if (isTextField(active)) {
            active.blur(); // chowa klawiaturę ekranową
            return;
        }
        // Szybkie podwójne Wstecz — zamknij aplikację (z każdej strony)
        if (quick) {
            lastBack = 0;
            if (!exitApp()) toast('Aby wyjść, użyj przycisku HOME na pilocie');
            return;
        }

        var now = Date.now();
        if (now - lastBack > BACK_CONFIRM_MS) {
            lastBack = now;
            var atHome = window.location.pathname === '/' || window.history.length <= 1;
            toast(atHome
                ? 'Naciśnij Wstecz jeszcze raz, aby zamknąć'
                : 'Naciśnij Wstecz jeszcze raz, aby cofnąć');
            return;
        }
        lastBack = 0;

        if (window.location.pathname === '/' || window.history.length <= 1) {
            if (!exitApp()) toast('Aby wyjść, użyj przycisku HOME na pilocie');
        } else {
            window.history.back();
        }
    }

    // ---------- Obsługa klawiszy ----------

    document.addEventListener('keydown', function (e) {
        var key = e.keyCode;
        var active = document.activeElement;

        if (key === KEY.BACK || key === KEY.ESC ||
            (key === KEY.BACKSPACE && !isTextField(active))) {
            e.preventDefault();
            onBack();
            return;
        }

        // OK na elemencie, który sam nie reaguje na Enter (np. div z data-action)
        if (key === KEY.ENTER && active && active !== document.body && !isNativelyActivated(active)) {
            e.preventDefault();
            active.click();
            return;
        }

        var dir = DIRS[key];
        if (!dir) return;

        // Gra może sama obsługiwać strzałki (np. koło fortuny) — nie przeszkadzamy.
        if (e.defaultPrevented) return;
        if (active && active.closest && active.closest('[data-tv-keys="native"]')) return;

        if (!hasValidFocus()) {
            e.preventDefault();
            focusInitial();
            return;
        }

        if (isTextField(active) && (dir === 'left' || dir === 'right') && !caretAtEdge(active, dir)) {
            return; // przesuwanie kursora w polu tekstowym
        }
        if (isDropdown(active) && (dir === 'left' || dir === 'right')) {
            e.preventDefault();
            stepSelect(active, dir === 'right' ? 1 : -1);
            return;
        }
        // lista wielowierszowa: ▲▼ przewijają ją same
        if (active.tagName === 'SELECT' && !isDropdown(active) && (dir === 'up' || dir === 'down')) return;

        var next = findNext(active, dir);
        if (next) {
            e.preventDefault();
            focusEl(next);
        } else if (dir === 'up' || dir === 'down') {
            // nic dalej w tym kierunku — przewiń stronę, jeśli jest co
            e.preventDefault();
            window.scrollBy(0, dir === 'down' ? 300 : -300);
        }
    }, false);

    // ---------- Fokus po załadowaniu (także po nawigacji Turbo) ----------

    function afterLoad() {
        installStyles(); // Turbo podmienia <head>/<body> — upewnij się, że styl jest
        lastBack = 0;
        if (!hasValidFocus()) setTimeout(focusInitial, 50);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', afterLoad);
    } else {
        afterLoad();
    }
    document.addEventListener('turbo:load', afterLoad);
    document.addEventListener('turbo:render', afterLoad);

    // Zaznaczony element zniknął (Mercure / Turbo Stream podmienił fragment) — zaznacz coś nowego
    var refocusTimer = null;
    document.addEventListener('focusout', function () {
        clearTimeout(refocusTimer);
        refocusTimer = setTimeout(function () {
            if (!hasValidFocus() && !isTextField(document.activeElement)) focusInitial();
        }, 150);
    }, true);
    // Chrome nie zawsze zgłasza utratę fokusu, gdy zaznaczony element zostaje usunięty —
    // pamiętamy ostatnio zaznaczony i sprawdzamy po zmianach w DOM, czy jeszcze istnieje.
    var lastFocused = null;
    var selectHintShown = false;
    document.addEventListener('focusin', function (e) {
        lastFocused = e.target;
        if (!selectHintShown && isDropdown(e.target)) {
            selectHintShown = true;
            toast('◀ ▶ — zmień wybór   •   ▲ ▼ — dalej');
        }
    }, true);
    function watchDom() {
        if (!window.MutationObserver || !document.documentElement) return false;
        new MutationObserver(function () {
            if (lastFocused && !document.documentElement.contains(lastFocused) && !hasValidFocus()) {
                lastFocused = null;
                clearTimeout(refocusTimer);
                refocusTimer = setTimeout(function () { if (!hasValidFocus()) focusInitial(); }, 100);
            }
        }).observe(document.documentElement, { childList: true, subtree: true });
        return true;
    }
    // Skrypt może ruszyć przed powstaniem <html> — wtedy obserwujemy po DOMContentLoaded
    if (!watchDom()) document.addEventListener('DOMContentLoaded', watchDom);
    document.addEventListener('turbo:frame-load', afterLoad);
    window.addEventListener('pageshow', afterLoad); // powrót z pamięci podręcznej (bfcache)
})();
