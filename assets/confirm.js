/**
 * Własne okno potwierdzenia zamiast window.confirm().
 *
 * Natywny confirm() na telewizorze jest brzydki, wyłazi poza motyw gry, a pilot
 * i przeglądarki TV obsługują go po swojemu (czasem wcale). <dialog> daje to samo
 * za darmo: fokus uwięziony w oknie, Esc zamyka, reszta strony jest nieaktywna.
 *
 * Zwraca Promise<boolean>: true = potwierdzone, false = anulowane (Esc, tło,
 * przycisk). Fokus startuje na „Anuluj" - pytamy o rzeczy nieodwracalne
 * (reset punktów, usunięcie drużyny), więc przypadkowe OK z pilota ma nie
 * kasować stanu.
 *
 * @param {string} message
 * @param {{confirmLabel?: string, cancelLabel?: string}} options
 * @returns {Promise<boolean>}
 */
export function confirmDialog(message, { confirmLabel = 'Tak', cancelLabel = 'Anuluj' } = {}) {
    // Starsze przeglądarki bez <dialog> (przed 2022) wracają do natywnego okna.
    if (typeof HTMLDialogElement === 'undefined') {
        return Promise.resolve(window.confirm(message));
    }

    return new Promise((resolve) => {
        const dialog = document.createElement('dialog');
        dialog.className = 'gk-confirm';
        dialog.setAttribute('aria-modal', 'true');

        const text = document.createElement('p');
        text.className = 'gk-confirm__text';
        text.textContent = message;

        const actions = document.createElement('div');
        actions.className = 'gk-confirm__actions';

        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'btn btn-ghost';
        cancel.textContent = cancelLabel;
        cancel.value = 'cancel';

        const ok = document.createElement('button');
        ok.type = 'button';
        ok.className = 'btn btn-primary';
        ok.textContent = confirmLabel;
        ok.value = 'ok';

        actions.append(cancel, ok);
        dialog.append(text, actions);
        document.body.append(dialog);

        let result = false;
        ok.addEventListener('click', () => {
            result = true;
            dialog.close();
        });
        cancel.addEventListener('click', () => dialog.close());
        // Kliknięcie w tło (poza treścią okna) anuluje.
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) {
                dialog.close();
            }
        });
        // Esc i dialog.close() kończą tym samym zdarzeniem.
        dialog.addEventListener('close', () => {
            dialog.remove();
            resolve(result);
        });

        dialog.showModal();
        cancel.focus();
    });
}
