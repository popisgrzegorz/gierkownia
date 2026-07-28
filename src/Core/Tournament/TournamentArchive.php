<?php

declare(strict_types=1);

namespace App\Core\Tournament;

use App\Core\Room\Room;
use App\Core\Room\RoomManager;
use Symfony\Component\Uid\Uuid;

/**
 * Zapis i wznowienie turnieju - jeden maly plik JSON na sesje.
 *
 * Bez bazy: zrzut to garstka nazw i tabela punktow za miejsca. Redis (stan live)
 * wygasa po TTL i ginie przy odtworzeniu kontenera, wiec do "wroćmy jutro" jest
 * za slaby; Postgres z encja i migracja to armata na jeden obiekt. Plik na dysku
 * przezywa restart, latwo go skopiowac i podejrzec okiem - w duchu gry, ktora ma
 * dzialac w LAN bez internetu.
 *
 * Wznowienie odtwarza druzyny i tabele turnieju w NOWYM pokoju. Gracze skanuja
 * nowy kod i wpisuja te sama nazwe - join() dopasowuje po nazwie i oddaje im
 * dorobek (patrz RoomManager::join / restoreTeams).
 */
final readonly class TournamentArchive
{
    public function __construct(
        private RoomManager $rooms,
        private Tournament $tournament,
        private string $tournamentDir,
    ) {
    }

    /**
     * Zrzuca biezacy turniej na dysk pod nazwa nadana przez hosta. Zwraca id
     * zapisu (do wznowienia).
     */
    public function save(Room $room, string $name): string
    {
        $name = trim($name);

        if ('' === $name) {
            $name = 'Turniej';
        }

        $id = Uuid::v4()->toBase58();

        $teams = array_map(
            static fn ($team): array => [
                'id' => $team->id,
                'name' => $team->name,
                'color' => $team->color,
                'seq' => $team->seq,
            ],
            $this->rooms->teams($room->code),
        );

        $snapshot = [
            'id' => $id,
            'name' => $name,
            'savedAt' => time(),
            'teams' => $teams,
            'games' => $this->tournament->export($room->code),
        ];

        if (!is_dir($this->tournamentDir) && !mkdir($this->tournamentDir, 0o775, true) && !is_dir($this->tournamentDir)) {
            throw new \RuntimeException(sprintf('Nie udalo sie utworzyc katalogu zapisow: %s', $this->tournamentDir));
        }

        file_put_contents(
            $this->path($id),
            json_encode($snapshot, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT),
        );

        return $id;
    }

    /**
     * Skrócone opisy zapisanych turniejow do listy wyboru, najnowsze pierwsze.
     *
     * @return list<array{id: string, name: string, savedAt: int, teamCount: int, games: int, leader: ?string}>
     */
    public function list(): array
    {
        if (!is_dir($this->tournamentDir)) {
            return [];
        }

        $summaries = [];

        foreach (glob($this->tournamentDir.'/*.json') ?: [] as $file) {
            $snapshot = $this->decode((string) file_get_contents($file));

            if (null === $snapshot) {
                continue; // uszkodzony albo obcy plik - pomijamy, nie wywalamy listy
            }

            $summaries[] = [
                'id' => $snapshot['id'],
                'name' => $snapshot['name'],
                'savedAt' => $snapshot['savedAt'],
                'teamCount' => \count($snapshot['teams']),
                'games' => \count($snapshot['games']),
                'leader' => $this->leader($snapshot),
            ];
        }

        usort($summaries, static fn (array $a, array $b): int => $b['savedAt'] <=> $a['savedAt']);

        return $summaries;
    }

    /**
     * Odtwarza zapisany turniej w nowym pokoju i zwraca ten pokoj (host dostaje
     * jego token do zapamietania i przekierowanie na ekran).
     */
    public function restore(string $id): Room
    {
        $path = $this->path($id);

        if (!is_file($path)) {
            throw new TournamentNotFoundException($id);
        }

        $snapshot = $this->decode((string) file_get_contents($path))
            ?? throw new TournamentNotFoundException($id);

        $room = $this->rooms->create();
        $this->rooms->restoreTeams($room->code, $snapshot['teams']);
        $this->tournament->import($room->code, $snapshot['games']);

        return $room;
    }

    private function path(string $id): string
    {
        // id z Uuid::toBase58 (same [1-9A-HJ-NP-Za-km-z]) - bez separatorow
        // sciezki, wiec nie ma jak wyjsc poza katalog zapisow.
        return sprintf('%s/%s.json', $this->tournamentDir, $id);
    }

    /**
     * Kto prowadzi - suma punktow za miejsca. null, gdy turniej jest jeszcze
     * przed pierwsza gra.
     *
     * @param array{teams: list<array{id: string, name: string, color: string, seq: int}>, games: list<array{type: string, label: string, at: int, points: array<string, int>, raw: array<string, int>}>} $snapshot
     */
    private function leader(array $snapshot): ?string
    {
        $best = null;
        $bestTotal = -1;

        foreach ($snapshot['teams'] as $team) {
            $total = 0;

            foreach ($snapshot['games'] as $game) {
                $total += $game['points'][$team['id']] ?? 0;
            }

            if ($total > $bestTotal) {
                $bestTotal = $total;
                $best = $team['name'];
            }
        }

        return $bestTotal > 0 ? $best : null;
    }

    /**
     * @return array{id: string, name: string, savedAt: int, teams: list<array{id: string, name: string, color: string, seq: int}>, games: list<array{type: string, label: string, at: int, points: array<string, int>, raw: array<string, int>}>}|null
     */
    private function decode(string $json): ?array
    {
        try {
            $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($data)
            || !\is_string($data['id'] ?? null)
            || !\is_string($data['name'] ?? null)
            || !\is_int($data['savedAt'] ?? null)
            || !\is_array($data['teams'] ?? null)
            || !\is_array($data['games'] ?? null)
        ) {
            return null;
        }

        /** @var array{id: string, name: string, savedAt: int, teams: list<array{id: string, name: string, color: string, seq: int}>, games: list<array{type: string, label: string, at: int, points: array<string, int>, raw: array<string, int>}>} $data */
        return $data;
    }
}
