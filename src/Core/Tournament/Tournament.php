<?php

declare(strict_types=1);

namespace App\Core\Tournament;

use App\Core\Game\GameRegistry;
use App\Core\Realtime\Event;
use App\Core\Realtime\Publisher;
use App\Core\Redis\RedisReader;
use App\Core\Room\Room;
use App\Core\Room\RoomKeys;
use App\Core\Room\RoomManager;
use App\Core\Score\Scoreboard;
use Predis\ClientInterface;

/**
 * Warstwa turnieju nad pojedynczymi grami.
 *
 * Kazda gra toczy sie we wlasnej skali punktow (melodia: pojedyncze, panstwa:
 * dziesiatki, kolo: setki) - sumowanie ich wprost na jednym tablo sprawialo, ze
 * setki z kola przykrywaly wszystko inne. Dlatego surowy wynik gry sluzy tylko
 * do jej rozstrzygniecia, a do turnieju przechodzi PUNKT ZA MIEJSCE: przy N
 * druzynach pierwsze miejsce dostaje N punktow, kazde kolejne o jeden mniej.
 * Skala gry przestaje miec znaczenie - liczy sie, kto ja wygral.
 *
 * Przeliczenie odpala sie w chwili zmiany gry (patrz recordCurrentGame): wtedy
 * surowy wynik zamienia sie na punkty turniejowe i zeruje pod nastepna gre.
 *
 * Dutch stoi poza turniejem - to gra indywidualna (jeden telefon = jeden gracz),
 * liczona "najnizsza suma wygrywa" we wlasnym kontekscie, nietykajaca Scoreboardu.
 * Jego tablica pozostaje pusta, wiec recordCurrentGame nie ma tam czego zapisac.
 */
final readonly class Tournament
{
    public function __construct(
        private ClientInterface $redis,
        private RedisReader $read,
        private RoomManager $rooms,
        private Scoreboard $scoreboard,
        private GameRegistry $registry,
        private Publisher $publisher,
        private int $roomTtl,
    ) {
    }

    /**
     * Zamyka biezaca gre w turnieju: surowy wynik na punkty za miejsce, dopisany
     * do tabeli, po czym zerowanie surowych punktow pod nastepna gre.
     *
     * Zwraca null, gdy nie ma czego liczyc (nikt nie zdobyl punktu - np. host
     * zmienil gre od razu, albo to byl Dutch, ktory tablicy nie dotyka). Wtedy
     * niczego nie dopisujemy ani nie zerujemy.
     *
     * @return array{type: string, label: string, points: array<string, int>}|null
     */
    public function recordCurrentGame(Room $room, string $gameType): ?array
    {
        $raw = $this->scoreboard->all($room->code);

        // Sama obecnosc druzyn to za malo - gra bez ani jednego punktu nie
        // rozstrzyga niczego, a rozdanie za nia miejsc premiowaloby drozyny za
        // to, ze host pomylil przycisk. array_sum() === 0 tu NIE wystarczy:
        // kara (-1 przez host) i bonus (+1) daja sume zero, mimo ze obie
        // druzyny naprawde cos ugraly - taka gra zostawalaby niezaliczona,
        // a jej surowe punkty nigdy by sie nie wyzerowaly pod nastepna runde.
        // array_filter() bez callbacku odsiewa same zera (0 jest falsy w PHP),
        // wiec pyta o wlasciwa rzecz: "czy ktokolwiek ma NIEZEROWY wynik".
        if ([] === array_filter($raw)) {
            return null;
        }

        $points = $this->placements($raw);
        $label = $this->registry->has($gameType) ? $this->registry->get($gameType)->name() : $gameType;

        $table = $this->load($room->code);
        $table['games'][] = [
            'type' => $gameType,
            'label' => $label,
            'at' => time(),
            'points' => $points,
            'raw' => $raw,
        ];
        $this->save($room->code, $table);

        // Surowe punkty w dol - nastepna gra ma zaczynac od zera we wlasnej
        // skali. Zerujemy przez Scoreboard, zeby poszedl event SCORE i tablo
        // na zywo tez wrocilo do zera.
        foreach ($raw as $teamId => $value) {
            if (0 !== $value) {
                $this->scoreboard->add($room->code, $teamId, -$value);
            }
        }

        $this->publisher->broadcast($room->code, new Event('tournament_awarded', [
            'game' => ['type' => $gameType, 'label' => $label],
            'standings' => $this->standings($room),
        ]));

        return ['type' => $gameType, 'label' => $label, 'points' => $points];
    }

    /**
     * Tabela turnieju do widoku: druzyny wg sumy punktow za miejsca, malejaco.
     * Zawiera tez druzyny z zerem - w turnieju miejsce w tabeli ma kazdy, kto
     * jest w pokoju, nawet zanim cokolwiek ugra.
     *
     * @return list<array{
     *     id: string,
     *     name: string,
     *     color: string,
     *     total: int,
     *     games: list<array{type: string, label: string, points: int}>
     * }>
     */
    public function standings(Room $room): array
    {
        $table = $this->load($room->code);
        $rows = [];

        foreach ($this->rooms->teams($room->code) as $team) {
            $games = [];
            $total = 0;

            foreach ($table['games'] as $game) {
                $points = $game['points'][$team->id] ?? 0;
                $total += $points;
                $games[] = ['type' => $game['type'], 'label' => $game['label'], 'points' => $points];
            }

            $rows[] = [
                'id' => $team->id,
                'name' => $team->name,
                'color' => $team->color,
                'total' => $total,
                'games' => $games,
            ];
        }

        // Malejaco po sumie; przy remisie zostaje kolejnosc dolaczenia (teams()
        // sortuje po seq), zeby ranking nie skakal bez powodu.
        usort($rows, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return $rows;
    }

    /** Ile gier turniej ma juz zaliczonych - do naglowka "po N grach". */
    public function playedGames(string $code): int
    {
        return \count($this->load($code)['games']);
    }

    /** Kasuje tabele turnieju. Reset rozgrywki = turniej tez rusza od zera. */
    public function clear(string $code): void
    {
        $this->redis->del([RoomKeys::tournament($code)]);
    }

    /**
     * Surowa tabela gier do zapisu na dysk. Zawiera punkty per teamId, wiec po
     * wczytaniu wskazuje te same druzyny (te same id).
     *
     * @return list<array{type: string, label: string, at: int, points: array<string, int>, raw: array<string, int>}>
     */
    public function export(string $code): array
    {
        return $this->load($code)['games'];
    }

    /**
     * Wczytuje tabele gier z zapisu do nowego pokoju.
     *
     * @param list<array{type: string, label: string, at: int, points: array<string, int>, raw: array<string, int>}> $games
     */
    public function import(string $code, array $games): void
    {
        $this->save($code, ['games' => $games]);
    }

    /**
     * Punkty za miejsca z surowych wynikow. Przy N druzynach: 1. miejsce = N,
     * kolejne o jeden mniej, ostatnie = 1.
     *
     * Remis: druzyny z tym samym wynikiem dostaja tyle samo - punkty za wyzsze
     * z zajmowanych miejsc (klasyczny ranking sportowy 1,1,3). Tak jest
     * sprawiedliwie: dwie najlepsze druzyny obie zdobyly najwiecej.
     *
     * @param array<string, int> $raw
     *
     * @return array<string, int>
     */
    private function placements(array $raw): array
    {
        $count = \count($raw);

        // Malejaco po wyniku; klucze (teamId) zachowujemy.
        arsort($raw);

        $points = [];
        $position = 0;
        $index = 0;
        $previousScore = null;

        foreach ($raw as $teamId => $score) {
            // Nowy, gorszy wynik przeskakuje na aktualna pozycje; remis zostaje
            // na pozycji poprzednika, wiec dostaje te same punkty.
            if ($score !== $previousScore) {
                $position = $index;
                $previousScore = $score;
            }

            $points[$teamId] = $count - $position;
            ++$index;
        }

        return $points;
    }

    /**
     * @return array{games: list<array{type: string, label: string, at: int, points: array<string, int>, raw: array<string, int>}>}
     */
    private function load(string $code): array
    {
        $json = $this->read->string(RoomKeys::tournament($code));

        if (null === $json) {
            return ['games' => []];
        }

        /** @var array{games?: list<array{type: string, label: string, at: int, points: array<string, int>, raw: array<string, int>}>} $decoded */
        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        return ['games' => $decoded['games'] ?? []];
    }

    /**
     * @param array{games: list<array{type: string, label: string, at: int, points: array<string, int>, raw: array<string, int>}>} $table
     */
    private function save(string $code, array $table): void
    {
        $this->redis->setex(
            RoomKeys::tournament($code),
            $this->roomTtl,
            json_encode($table, \JSON_THROW_ON_ERROR),
        );
    }
}
