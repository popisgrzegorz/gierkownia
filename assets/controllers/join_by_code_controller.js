import { Controller } from '@hotwired/stimulus';

/**
 * Kod pokoju przepisywany z telewizora. Klawiatura telefonu podpowiada male
 * litery i spacje, a trasa przyjmuje wylacznie [A-Z]{4} - normalizujemy w locie,
 * zeby "abcd " nie konczylo sie komunikatem "nie ma takiego pokoju".
 */
export default class extends Controller {
    static targets = ['input'];

    connect() {
        this.inputTarget.addEventListener('input', () => {
            this.inputTarget.value = this.inputTarget.value
                .toUpperCase()
                .replace(/[^A-Z]/g, '')
                .slice(0, 4);
        });
    }
}
