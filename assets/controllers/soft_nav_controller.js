import { Controller } from '@hotwired/stimulus';
import { softNavigate } from '../soft_nav.js';
import { confirmDialog } from '../confirm.js';

/**
 * Wysyła formularz bez pełnego przeładowania strony (patrz soft_nav.js).
 *
 * Dzięki temu „Zmień grę" i „Reset" nie wyrzucają hosta z pełnego ekranu.
 * Potwierdzenie (data-confirm) obsługujemy tutaj, a nie przez onsubmit -
 * inaczej odrzucone „Anuluj" i tak przeszłoby przez ten handler.
 */
export default class extends Controller {
    async submit(event) {
        event.preventDefault();

        // Bez tego drugi klik w "Reset"/"Zmień grę" w oknie oczekiwania na
        // odpowiedź wystrzeliwuje drugi POST - a to akurat akcje niszczące
        // stan (zerowanie punktów, zamykanie gry), więc podwójne wykonanie
        // nie jest nieszkodliwe.
        if (this.submitting) {
            return;
        }

        const form = event.currentTarget;
        const question = form.dataset.confirm;

        // submitting ustawiamy PRZED oczekiwaniem na okno: drugi klik, gdy pytanie
        // wisi na ekranie, nie ma otworzyć drugiego.
        this.submitting = true;

        if (question && !(await confirmDialog(question))) {
            this.submitting = false;
            return;
        }

        try {
            await softNavigate(form.action, {
                method: (form.method || 'post').toUpperCase(),
                body: new FormData(form),
            });
        } finally {
            // softNavigate zwykle podmienia <body> (ten formularz znika razem
            // z nim) albo nawiguje twardo - ale gdyby mimo to zostać na
            // miejscu (np. odpowiedź 2xx bez zmiany widoku), kolejny klik ma
            // znowu działać.
            this.submitting = false;
        }
    }
}
