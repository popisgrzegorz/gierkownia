import { Controller } from '@hotwired/stimulus';

/**
 * Tabela turnieju: sumy punktów za miejsca ze wszystkich rozegranych gier.
 *
 * Nie otwiera własnego SSE - piggybackuje na strumieniu, który room-board
 * rozgłasza jako `gierki:event`. Aktualizuje się przy `tournament_awarded`
 * (host zmienił grę, więc doszły punkty za miejsca), przy dołączeniu drużyny
 * i przy resecie. Surowy wynik bieżącej gry to inna sprawa - ten pokazuje
 * tablo z boku, nie turniej.
 */
export default class extends Controller {
    static targets = ['list', 'empty', 'note'];

    connect() {
        this.onEvent = (event) => this.#handle(event.detail);
        window.addEventListener('gierki:event', this.onEvent);
    }

    disconnect() {
        window.removeEventListener('gierki:event', this.onEvent);
    }

    #handle(event) {
        switch (event.type) {
            case 'tournament_awarded':
                this.#renderStandings(event.standings);
                break;

            case 'team_joined':
                this.#addTeam(event.team);
                break;

            case 'reset':
                // Reset zeruje turniej - zostają drużyny, znika dorobek.
                [...this.listTarget.children].forEach((row) => {
                    row.querySelector('[data-total]').textContent = '0';
                    row.querySelector('.team-games')?.replaceChildren();
                });
                this.#renumber();
                break;
        }
    }

    /** @param {Array<{id:string,name:string,color:string,total:number,games:Array}>} standings */
    #renderStandings(standings) {
        if (!Array.isArray(standings)) {
            return;
        }

        this.listTarget.replaceChildren(...standings.map((team, index) => this.#row(team, index + 1)));

        if (this.hasEmptyTarget) {
            this.emptyTarget.hidden = 0 < standings.length;
        }
        if (this.hasNoteTarget) {
            const played = standings[0]?.games?.length ?? 0;
            this.noteTarget.textContent = this.#playedLabel(played);
        }
    }

    #addTeam(team) {
        if (!team?.id || this.listTarget.querySelector(`[data-team-id="${CSS.escape(team.id)}"]`)) {
            return;
        }

        this.listTarget.append(this.#row(
            { id: team.id, name: team.name, color: team.color, total: 0, games: [] },
            this.listTarget.children.length + 1,
        ));

        if (this.hasEmptyTarget) {
            this.emptyTarget.hidden = true;
        }
    }

    #row(team, rank) {
        const li = document.createElement('li');
        li.className = 'team';
        li.dataset.teamId = team.id;
        li.style.setProperty('--team-color', team.color);

        const rankEl = document.createElement('span');
        rankEl.className = 'team-rank';
        rankEl.textContent = String(rank);

        const name = document.createElement('span');
        name.className = 'team-name';
        name.textContent = team.name;

        const games = document.createElement('span');
        games.className = 'team-games';
        (team.games ?? []).forEach((game) => {
            if (0 === game.points) {
                return;
            }

            const pill = document.createElement('span');
            pill.className = 'team-game-pill';
            pill.textContent = `${game.label} +${game.points}`;
            games.append(pill);
        });

        const total = document.createElement('span');
        total.className = 'team-score';
        total.dataset.total = '';
        total.textContent = String(team.total ?? 0);

        li.append(rankEl, name, games, total);

        return li;
    }

    #renumber() {
        [...this.listTarget.children].forEach((row, index) => {
            const rank = row.querySelector('.team-rank');

            if (rank) {
                rank.textContent = String(index + 1);
            }
        });
    }

    #playedLabel(played) {
        if (0 === played) {
            return 'Turniej zacznie się po pierwszej grze.';
        }

        return `Po ${played} ${1 === played ? 'grze' : 'grach'}.`;
    }
}
