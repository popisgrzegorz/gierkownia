/**
 * Efekty dźwiękowe gry — syntezowane w przeglądarce, bez ani jednego pliku audio.
 *
 * Dlaczego synteza, a nie nagrania:
 *  - w repo nie lądują megabajty binariów, a przy starcie nie ma czego ściągać
 *    (gra ma dawać radę także w LAN, gdzie nie ma internetu);
 *  - nie ma pytania o licencje na dźwięki z cudzych bibliotek;
 *  - klik pada w tej samej klatce, w której kafelek się obraca — pierwszy strzał
 *    z pliku musiałby najpierw poczekać na pobranie i dekodowanie.
 * Cena: to jest brzęczyk, a nie orkiestra. Gdyby kiedyś miały wejść prawdziwe
 * nagrania, wystarczy podmienić `CUES` na odtwarzanie buforów — reszta kodu,
 * łącznie z wyciszaniem i polityką autoplay, zostaje bez zmian.
 *
 * Dźwięk należy do telewizora, nie do telefonów: przy stole gra jedna sala,
 * a dziesięć telefonów brzęczących z sekundowym rozjazdem to hałas, nie efekt.
 */

const STORAGE_KEY = 'gierki:sfx';

/** Poziom wyjścia. Reszta głośności jest po stronie telewizora. */
const MASTER = 0.9;

/** Nuty, z których zbudowane są fanfary i dźwięki trafienia (herce). */
const NOTE = {
    C4: 261.63, E4: 329.63, G4: 392.0,
    C5: 523.25, E5: 659.25, G5: 783.99, A5: 880.0,
    C6: 1046.5, E6: 1318.51, G6: 1567.98,
};

/**
 * Pojedynczy ton z obwiednią.
 *
 * Obwiednia jest wykładnicza, bo ucho słyszy głośność logarytmicznie —
 * liniowe wygaszanie brzmi jak dźwięk ucięty w połowie.
 */
function tone(a, { at, freq, to, type = 'sine', dur = 0.25, gain = 0.2, attack = 0.008, lowpass }) {
    const osc = a.ctx.createOscillator();
    const env = a.ctx.createGain();

    osc.type = type;
    osc.frequency.setValueAtTime(freq, at);

    if (undefined !== to && to !== freq) {
        osc.frequency.exponentialRampToValueAtTime(Math.max(20, to), at + dur);
    }

    env.gain.setValueAtTime(0.0001, at);
    env.gain.exponentialRampToValueAtTime(gain, at + attack);
    env.gain.exponentialRampToValueAtTime(0.0001, at + dur);

    // Piła bez filtra kłuje w uszy na głośnym telewizorze; dolnoprzepustowy
    // filtr zostawia z niej "blachę", a zdejmuje piach.
    if (lowpass) {
        const filter = a.ctx.createBiquadFilter();
        filter.type = 'lowpass';
        filter.frequency.setValueAtTime(lowpass, at);
        osc.connect(filter).connect(env).connect(a.out);
    } else {
        osc.connect(env).connect(a.out);
    }

    osc.start(at);
    osc.stop(at + dur + 0.03);
}

/** Szum przez filtr — z tego są klaśnięcia, kliknięcia i świsty. */
function noise(a, { at, dur = 0.2, gain = 0.25, type = 'bandpass', freq = 1200, to, q = 1, attack = 0.004 }) {
    const src = a.ctx.createBufferSource();
    const filter = a.ctx.createBiquadFilter();
    const env = a.ctx.createGain();

    src.buffer = a.noise;
    // Losowy punkt startu w buforze: dwa klaśnięcia wycięte z tego samego
    // miejsca brzmiałyby jak jedno nagranie puszczone dwa razy.
    const offset = Math.random() * Math.max(0, a.noise.duration - dur - 0.05);

    filter.type = type;
    filter.frequency.setValueAtTime(freq, at);
    filter.Q.value = q;

    if (undefined !== to) {
        filter.frequency.exponentialRampToValueAtTime(Math.max(20, to), at + dur);
    }

    env.gain.setValueAtTime(0.0001, at);
    env.gain.exponentialRampToValueAtTime(gain, at + attack);
    env.gain.exponentialRampToValueAtTime(0.0001, at + dur);

    src.connect(filter).connect(env).connect(a.out);
    src.start(at, offset);
    src.stop(at + dur + 0.05);
}

/**
 * Oklaski: kilkadziesiąt osobnych klaśnięć plus szum sali w tle.
 *
 * Sam szum brzmi jak deszcz — dopiero pojedyncze, nierówno rozrzucone
 * uderzenia robią z niego ludzi. Najgęściej jest na początku, potem owacja
 * rzednie, tak jak przy stole.
 */
function applause(a, t, dur) {
    noise(a, { at: t, dur, gain: 0.08, type: 'highpass', freq: 900, attack: 0.18 });

    // Gęsto: dopiero tłum klaśnięć brzmi jak sala, a nie jak ktoś klaszcze sam.
    const claps = Math.round(dur * 40);

    for (let i = 0; i < claps; i += 1) {
        // Pierwiastkowy rozkład czasu zagęszcza początek bez rysowania obwiedni.
        const at = t + dur * Math.random() ** 0.6;
        const fade = 1 - 0.6 * ((at - t) / dur);

        noise(a, {
            at,
            dur: 0.03 + 0.02 * Math.random(),
            gain: (0.07 + 0.08 * Math.random()) * fade,
            type: 'bandpass',
            freq: 1100 + 1900 * Math.random(),
            q: 0.9,
        });
    }
}

/** Fanfara "ta-da-da-daaa" — trzy krótkie i jedna trzymana. */
function fanfare(a, t) {
    let at = t;

    [[NOTE.C5, 0.15], [NOTE.E5, 0.15], [NOTE.G5, 0.15], [NOTE.C6, 0.75]].forEach(([freq, dur]) => {
        tone(a, { at, freq, dur: dur * 1.15, gain: 0.2, type: 'sawtooth', lowpass: 2800 });
        // Oktawa niżej daje fanfarze ciało; sama linia melodyczna brzmi cienko.
        tone(a, { at, freq: freq / 2, dur: dur * 1.15, gain: 0.11, type: 'triangle' });
        at += dur;
    });
}

/**
 * Słownik dźwięków. `minor` — drobiazg, który ustępuje wielkim dźwiękom;
 * `holds` — na ile sekund dany dźwięk zajmuje scenę.
 */
const CUES = {
    /** Telefon dołączył do pokoju. */
    join: {
        minor: true,
        run: (a, t) => {
            tone(a, { at: t, freq: NOTE.E5, dur: 0.1, gain: 0.16, type: 'triangle' });
            tone(a, { at: t + 0.09, freq: NOTE.A5, dur: 0.18, gain: 0.16, type: 'triangle' });
        },
    },

    /** Punkt na tablo. */
    point: {
        minor: true,
        run: (a, t) => {
            tone(a, { at: t, freq: NOTE.G5, dur: 0.16, gain: 0.22, type: 'triangle' });
            tone(a, { at: t + 0.06, freq: NOTE.C6, dur: 0.34, gain: 0.16, type: 'sine' });
        },
    },

    /** Ktoś odpowiedział, ale jeszcze nie wiadomo co. */
    answer: {
        minor: true,
        run: (a, t) => tone(a, { at: t, freq: NOTE.C5, to: NOTE.G5, dur: 0.12, gain: 0.17, type: 'triangle' }),
    },

    /** Ostatnie sekundy rundy. */
    tick: {
        minor: true,
        run: (a, t) => tone(a, { at: t, freq: 960, to: 820, type: 'square', dur: 0.06, gain: 0.13 }),
    },

    /** Ostatnia sekunda — wyżej i głośniej, żeby było słychać, że to już koniec. */
    tickLast: {
        minor: true,
        run: (a, t) => tone(a, { at: t, freq: 1180, to: 980, type: 'square', dur: 0.1, gain: 0.2 }),
    },

    /** Koniec czasu: klakson w dół, trzy razy — nie do przeoczenia. */
    timeUp: {
        holds: 1.1,
        run: (a, t) => {
            tone(a, { at: t, freq: 440, type: 'sawtooth', dur: 0.24, gain: 0.24, lowpass: 1900 });
            tone(a, { at: t + 0.26, freq: 392, type: 'sawtooth', dur: 0.24, gain: 0.24, lowpass: 1800 });
            tone(a, { at: t + 0.52, freq: 294, type: 'sawtooth', dur: 0.6, gain: 0.27, lowpass: 1600 });
        },
    },

    /** Buzzery otwarte — można wciskać. */
    armed: {
        minor: true,
        run: (a, t) => {
            tone(a, { at: t, freq: NOTE.C5, dur: 0.08, gain: 0.17, type: 'square' });
            tone(a, { at: t + 0.08, freq: NOTE.G5, dur: 0.13, gain: 0.17, type: 'square' });
        },
    },

    /** Wciśnięty buzzer: klasyczny brzęczyk teleturnieju. */
    buzz: {
        holds: 0.5,
        run: (a, t) => {
            tone(a, { at: t, freq: 210, to: 180, type: 'square', dur: 0.44, gain: 0.26, lowpass: 1500 });
            tone(a, { at: t, freq: 105, to: 90, type: 'sawtooth', dur: 0.44, gain: 0.16, lowpass: 950 });
        },
    },

    /** Dobra odpowiedź — pochód w górę zakończony dzwonkiem. */
    correct: {
        holds: 0.9,
        run: (a, t) => {
            [NOTE.C5, NOTE.E5, NOTE.G5, NOTE.C6].forEach((freq, i) => {
                tone(a, { at: t + i * 0.075, freq, dur: 0.3, gain: 0.23, type: 'triangle' });
            });
            tone(a, { at: t + 0.3, freq: NOTE.G6, dur: 0.55, gain: 0.13, type: 'sine' });
        },
    },

    /** Porażka: buczenie z dwóch rozstrojonych pił, żeby dudniło. */
    wrong: {
        holds: 1.0,
        run: (a, t) => {
            tone(a, { at: t, freq: 170, to: 118, type: 'sawtooth', dur: 0.72, gain: 0.26, lowpass: 1300 });
            tone(a, { at: t, freq: 176, to: 114, type: 'sawtooth', dur: 0.72, gain: 0.2, lowpass: 1300 });
        },
    },

    applause: { holds: 2.4, run: (a, t) => applause(a, t, 2.2) },

    /** Zwycięstwo: fanfara, a na nią wchodzą oklaski. */
    victory: {
        holds: 3.4,
        run: (a, t) => {
            fanfare(a, t);
            applause(a, t + 0.55, 2.8);
        },
    },

    /**
     * Odsłonięte rozwiązanie. Drobiazg z rozmysłem: przy trafionej odpowiedzi
     * rozwiązanie odsłania się razem z owacją i dzwonek nie ma się w nią pchać.
     */
    reveal: {
        minor: true,
        run: (a, t) => {
            tone(a, { at: t, freq: NOTE.C6, dur: 0.5, gain: 0.16, type: 'sine' });
            tone(a, { at: t + 0.02, freq: NOTE.G5, dur: 0.5, gain: 0.11, type: 'triangle' });
        },
    },

    /** Koło rusza: ton i szum w górę, zanim wejdzie klekot kołków. */
    spin: {
        minor: true,
        run: (a, t) => {
            tone(a, { at: t, freq: 140, to: 520, type: 'sawtooth', dur: 0.5, gain: 0.13, lowpass: 1800 });
            noise(a, { at: t, dur: 0.5, gain: 0.07, type: 'bandpass', freq: 400, to: 2200, q: 0.7 });
        },
    },

    /** Obrót kafelka z literą. */
    flip: {
        minor: true,
        run: (a, t) => {
            noise(a, { at: t, dur: 0.05, gain: 0.14, type: 'bandpass', freq: 1400, q: 1.2 });
            tone(a, { at: t, freq: 520, to: 300, type: 'triangle', dur: 0.07, gain: 0.09 });
        },
    },

    /** Jeden sektor koła pod wskaźnikiem — klapka o kołki. */
    wheelTick: {
        minor: true,
        run: (a, t) => noise(a, { at: t, dur: 0.04, gain: 0.18, type: 'bandpass', freq: 2400 + 400 * Math.random(), q: 2 }),
    },

    /** Krok bębna z literą. */
    drum: {
        minor: true,
        run: (a, t) => {
            noise(a, { at: t, dur: 0.06, gain: 0.14, type: 'lowpass', freq: 900 });
            tone(a, { at: t, freq: 150, to: 90, type: 'triangle', dur: 0.07, gain: 0.12 });
        },
    },

    /** Bęben stanął: przybicie pieczątki i dzwonek. */
    stamp: {
        holds: 0.8,
        run: (a, t) => {
            noise(a, { at: t, dur: 0.18, gain: 0.2, type: 'lowpass', freq: 500 });
            tone(a, { at: t, freq: 120, to: 55, type: 'sine', dur: 0.25, gain: 0.24 });
            tone(a, { at: t + 0.1, freq: NOTE.E6, dur: 0.45, gain: 0.11, type: 'sine' });
        },
    },

    /** Bankrut: zjazd w dół i głuchy łomot na dnie. */
    bankrupt: {
        holds: 1.5,
        run: (a, t) => {
            tone(a, { at: t, freq: 520, to: 70, type: 'sawtooth', dur: 0.9, gain: 0.17, lowpass: 1600 });
            noise(a, { at: t + 0.85, dur: 0.3, gain: 0.18, type: 'lowpass', freq: 300 });
            tone(a, { at: t + 0.85, freq: 90, to: 45, type: 'sine', dur: 0.4, gain: 0.24 });
        },
    },

    /** Przejście: nowa karta, nowe pytanie, nowe hasło. */
    whoosh: {
        minor: true,
        run: (a, t) => noise(a, { at: t, dur: 0.34, gain: 0.15, type: 'bandpass', freq: 300, to: 2600, q: 0.8 }),
    },

    /** Podpowiedź (koło ratunkowe) — iskierki w górę. */
    sparkle: {
        holds: 0.7,
        run: (a, t) => {
            [NOTE.G5, NOTE.C6, NOTE.E6, NOTE.G6].forEach((freq, i) => {
                tone(a, { at: t + i * 0.06, freq, dur: 0.22, gain: 0.14, type: 'sine' });
            });
        },
    },

    /** Gwizdek: ktoś zawołał STOP. */
    whistle: {
        holds: 0.8,
        run: (a, t) => {
            noise(a, { at: t, dur: 0.42, gain: 0.14, type: 'bandpass', freq: 2300, q: 14 });
            // Dwa bliskie tony dudnią o siebie - stąd "trel" prawdziwego gwizdka.
            tone(a, { at: t, freq: 2180, dur: 0.42, gain: 0.07, type: 'sine' });
            tone(a, { at: t, freq: 2255, dur: 0.42, gain: 0.07, type: 'sine' });
        },
    },
};

class Sfx {
    #ctx = null;
    #out = null;
    #noise = null;
    #muted = false;
    /** Do kiedy (czas kontekstu) scena należy do dużego dźwięku. */
    #busyUntil = 0;

    constructor() {
        try {
            this.#muted = 'off' === localStorage.getItem(STORAGE_KEY);
        } catch {
            this.#muted = false; // prywatne okno bez localStorage - gramy
        }
    }

    get muted() {
        return this.#muted;
    }

    /** Przełącza wyciszenie i zwraca nowy stan. */
    toggle() {
        this.#muted = !this.#muted;

        try {
            localStorage.setItem(STORAGE_KEY, this.#muted ? 'off' : 'on');
        } catch {
            // Bez pamięci ustawienie żyje do przeładowania strony - trudno.
        }

        if (this.#out) {
            this.#out.gain.value = this.#muted ? 0 : MASTER;
        }

        // Potwierdzenie, że dźwięk wrócił: przy telewizorze nie ma innego
        // sposobu, żeby sprawdzić głośniki przed pierwszą rundą.
        if (!this.#muted) {
            this.play('join');
        }

        return this.#muted;
    }

    /**
     * Polityka autoplay: przeglądarka trzyma kontekst uśpiony, dopóki ktoś
     * czegoś nie kliknie. Wołamy to z pierwszego gestu na stronie - inaczej
     * pierwsza runda poszłaby bezgłośnie, a host nie miałby o tym pojęcia.
     */
    unlock() {
        this.#audio()?.resume?.().catch(() => {});
    }

    /**
     * Gra dźwięk o podanej nazwie.
     *
     * @param {string} name    klucz z CUES
     * @param {object} options delay - opóźnienie w sekundach
     */
    play(name, { delay = 0 } = {}) {
        const cue = CUES[name];

        if (this.#muted || !cue) {
            return;
        }

        const ctx = this.#audio();

        if (!ctx) {
            return;
        }

        if ('running' !== ctx.state) {
            // Kontekst śpi (brak gestu użytkownika) - currentTime stoi
            // w miejscu, więc zaplanowanie dźwięku TERAZ zabrzmiałoby dopiero
            // po odblokowaniu, razem z KAŻDYM innym dźwiękiem zaplanowanym
            // w tym oknie (wszystkie na ten sam, zamrożony moment) - stąd
            // kakofonia wszystkiego naraz po pierwszym kliknięciu. Lepiej
            // pominąć pojedynczy efekt niż zaplanować kolejkę, która i tak
            // wystrzeli jednocześnie.
            ctx.resume?.().catch(() => {});

            return;
        }

        const at = ctx.currentTime + delay + 0.01;

        // Drobiazg nie wchodzi w oklaski. Ping za punkt w środku owacji brzmi
        // jak usterka, a i tak nikt go tam nie usłyszy.
        if (cue.minor && at < this.#busyUntil) {
            return;
        }

        if (cue.holds) {
            this.#busyUntil = at + cue.holds;
        }

        cue.run({ ctx, out: this.#out, noise: this.#noise }, at);
    }

    /**
     * Kontekst budujemy dopiero przy pierwszym dźwięku: utworzony na starcie
     * strony i tak wstaje uśpiony, a przeglądarka wypisuje o tym ostrzeżenie
     * do konsoli na każdym ekranie, także tam, gdzie nic nie gra.
     */
    #audio() {
        if (this.#ctx) {
            return this.#ctx;
        }

        const Ctor = window.AudioContext ?? window.webkitAudioContext;

        if (!Ctor) {
            return null;
        }

        this.#ctx = new Ctor();

        // Kompresor na wyjściu: fanfara z oklaskami to kilkadziesiąt źródeł
        // naraz i bez niego suma trzeszczy na obciętym szczycie.
        const limiter = this.#ctx.createDynamicsCompressor();
        limiter.threshold.value = -12;
        limiter.ratio.value = 8;

        this.#out = this.#ctx.createGain();
        this.#out.gain.value = this.#muted ? 0 : MASTER;
        this.#out.connect(limiter).connect(this.#ctx.destination);

        // Dwie sekundy szumu na całą stronę - z tego bufora wycinamy każde
        // klaśnięcie i każdy klik, zamiast generować je od nowa.
        const frames = this.#ctx.sampleRate * 2;
        this.#noise = this.#ctx.createBuffer(1, frames, this.#ctx.sampleRate);
        const data = this.#noise.getChannelData(0);

        for (let i = 0; i < frames; i += 1) {
            data[i] = Math.random() * 2 - 1;
        }

        return this.#ctx;
    }
}

export const sfx = new Sfx();
