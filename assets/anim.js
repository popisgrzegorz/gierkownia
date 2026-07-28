/**
 * Wspólne efekty GSAP dla gier - żeby każdy kontroler nie pisał tego samego.
 *
 * Wszystkie szanują `prefers-reduced-motion`: przy wyłączonych animacjach po
 * prostu nic się nie dzieje, a element zostaje w stanie docelowym.
 */
import gsap from 'gsap';

function reduced() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/** Wskakuje z powiększenia z lekkim odbiciem - do banerów i wyników. */
export function popIn(el, opts = {}) {
    if (!el || reduced()) {
        return;
    }

    gsap.from(el, {
        duration: 0.55,
        scale: 0.4,
        opacity: 0,
        rotation: 0,
        ease: 'back.out(1.7)',
        ...opts,
    });
}

/** Kaskada wejścia dla listy elementów (karty, komórki, plakietki). */
export function cascade(els, opts = {}) {
    const list = [...(els ?? [])];

    if (0 === list.length || reduced()) {
        return;
    }

    gsap.from(list, {
        duration: 0.5,
        y: 26,
        opacity: 0,
        stagger: 0.07,
        ease: 'power2.out',
        ...opts,
    });
}

/** Radosny „strzał" - do trafień, wygranych, rozwiązań. */
export function celebrate(el, opts = {}) {
    if (!el || reduced()) {
        return;
    }

    gsap.fromTo(el,
        { scale: 1 },
        { duration: 0.5, scale: 1.14, rotation: opts.rotation ?? 0, yoyo: true, repeat: 1, ease: 'power2.inOut' });
}

/** Potrząśnięcie - do pomyłek i pudeł. */
export function shake(el) {
    if (!el || reduced()) {
        return;
    }

    gsap.fromTo(el, { x: -10 }, { duration: 0.5, x: 0, ease: 'elastic.out(1, 0.3)' });
}

/** Obrót 3D awersem do góry - do odsłaniania kafelków/kart. */
export function flipIn(els, opts = {}) {
    const list = [...(els ?? [])];

    if (0 === list.length || reduced()) {
        return;
    }

    gsap.from(list, {
        duration: 0.55,
        rotationY: 90,
        opacity: 0,
        transformPerspective: 500,
        stagger: 0.08,
        ease: 'power2.out',
        ...opts,
    });
}
