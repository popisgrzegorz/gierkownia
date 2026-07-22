/**
 * Cienka nakladka na EventSource dla huba Mercure.
 *
 * withCredentials jest obowiazkowe: prywatne topici (/host, /team/{id}) sa
 * autoryzowane ciasteczkiem mercureAuthorization, a bez tej flagi przegladarka
 * go nie dolaczy i host nie zobaczy niczego poza broadcastem.
 *
 * Ponowne laczenie zostawiamy EventSource - robi to sam z backoffem. Zglaszamy
 * tylko zmiany stanu, zeby ekran mogl pokazac "rozlaczony" zamiast po cichu
 * zamarznac na nieaktualnym tablo.
 */
export class MercureStream {
    #url;
    #onEvent;
    #onState;
    #source = null;

    constructor(url, { onEvent, onState = () => {} }) {
        this.#url = url;
        this.#onEvent = onEvent;
        this.#onState = onState;
    }

    connect() {
        this.#onState('connecting');
        this.#source = new EventSource(this.#url, { withCredentials: true });

        this.#source.onopen = () => this.#onState('online');
        this.#source.onerror = () => this.#onState(
            this.#source?.readyState === EventSource.CLOSED ? 'offline' : 'connecting',
        );

        this.#source.onmessage = (message) => {
            let event;
            try {
                event = JSON.parse(message.data);
            } catch {
                return; // nie nasz format - ignorujemy zamiast wywracac ekran
            }

            if (event?.type) {
                this.#onEvent(event);
            }
        };
    }

    disconnect() {
        this.#source?.close();
        this.#source = null;
    }
}
