/**
 * Rysowanie kart Dutcha - wspólne dla ekranu hosta i telefonu, żeby karta
 * wyglądała wszędzie tak samo: rogi z figurą i kolorem plus duży znak na środku,
 * jak na prawdziwej karcie.
 *
 * Serwer podaje kartę jako `{ label: "10♦", value, red }`. Figurę i kolor
 * wyłuskujemy z etykiety - kolor to zawsze ostatni znak.
 */

const SUITS = new Set(['♠', '♥', '♦', '♣']);

/* Figury dostają grafikę na środku (szachowe: koń=walet, hetman=dama, król),
   żeby od jednego rzutu oka odróżnić je od kart z liczbami. Kolor dziedziczą
   po karcie, więc dama kier jest czerwona, a trefl - czarny. */
const FIGURE = { J: '♞', Q: '♛', K: '♚' };

function split(label) {
    const suit = label.slice(-1);

    return SUITS.has(suit) ? { rank: label.slice(0, -1), suit } : { rank: label, suit: '' };
}

function corner(rank, suit, where) {
    const el = document.createElement('span');
    el.className = `dutch-corner dutch-corner-${where}`;

    const r = document.createElement('b');
    r.textContent = rank;

    const s = document.createElement('span');
    s.textContent = suit;

    el.append(r, s);

    return el;
}

function pip(symbol, figure) {
    const el = document.createElement('span');
    el.className = figure ? 'dutch-pip dutch-pip-figure' : 'dutch-pip';
    el.textContent = symbol;

    return el;
}

/**
 * Odkrywa istniejący element karty (nie rusza jego klas pomocniczych ani
 * data-slot - dokłada tylko warstwę „awers”).
 */
export function paint(cell, card) {
    const { rank, suit } = split(card.label);

    cell.classList.remove('dutch-down', 'dutch-empty');
    cell.classList.add('dutch-up');
    cell.classList.toggle('red', Boolean(card.red));

    const figure = FIGURE[rank];

    cell.replaceChildren(
        corner(rank, suit, 'tl'),
        pip(figure ?? (suit || rank), Boolean(figure)),
        corner(rank, suit, 'br'),
    );
}

/** Zakrywa kartę (rewers), zostawiając klasy pomocnicze i data-slot. */
export function blank(cell) {
    cell.classList.remove('dutch-up', 'red', 'dutch-empty');
    cell.classList.add('dutch-down');
    cell.replaceChildren();
}

/** Nowa, samodzielna karta - do animacji lotu i podglądu dobranej. */
export function create(card) {
    const cell = document.createElement('span');
    cell.className = 'dutch-card';
    paint(cell, card);

    return cell;
}
