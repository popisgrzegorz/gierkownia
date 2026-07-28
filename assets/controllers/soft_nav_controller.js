import { Controller } from '@hotwired/stimulus';
import { softNavigate } from '../soft_nav.js';

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

        const form = event.currentTarget;
        const question = form.dataset.confirm;

        if (question && !window.confirm(question)) {
            return;
        }

        await softNavigate(form.action, {
            method: (form.method || 'post').toUpperCase(),
            body: new FormData(form),
        });
    }
}
