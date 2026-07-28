<?php

declare(strict_types=1);

namespace App\Tests\Core\Tournament;

use App\Core\Game\GameRegistry;
use App\Core\Realtime\Publisher;
use App\Core\Redis\RedisReader;
use App\Core\Room\Room;
use App\Core\Room\RoomCodeGenerator;
use App\Core\Room\RoomManager;
use App\Core\Score\Scoreboard;
use App\Core\Tournament\Tournament;
use App\Tests\RedisTestCase;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

/**
 * Punktacja turnieju: surowy wynik gry (o dowolnej skali) zamienia sie na
 * punkty za miejsce dopiero przy zmianie gry. To jest to, co sprawia, ze setki
 * z kola nie przykrywaja pojedynczych punktow z melodii - liczy sie miejsce.
 */
final class TournamentTest extends RedisTestCase
{
    private const TTL = 60;

    private RoomManager $rooms;
    private Scoreboard $scoreboard;
    private Tournament $tournament;

    /** @var list<Update> */
    private array $published = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->published = [];

        $hub = new MockHub(
            url: 'http://localhost/.well-known/mercure',
            jwtProvider: new StaticTokenProvider('test'),
            publisher: function (Update $update): string {
                $this->published[] = $update;

                return 'test-id';
            },
        );

        $reader = new RedisReader($this->redis);
        $publisher = new Publisher($hub);
        $this->rooms = new RoomManager($this->redis, $reader, new RoomCodeGenerator(), self::TTL);
        $this->scoreboard = new Scoreboard($this->redis, $reader, $this->rooms, $publisher, self::TTL);
        $this->tournament = new Tournament(
            $this->redis,
            $reader,
            $this->rooms,
            $this->scoreboard,
            new GameRegistry([]),
            $publisher,
            self::TTL,
        );
    }

    /**
     * Sedno: gra o setkach punktow i gra o pojedynczych daja do turnieju tyle
     * samo - punkt za miejsce. Sumuja sie przez kolejne gry.
     */
    public function testRawScoreBecomesPlacementPointsAndAccumulates(): void
    {
        $room = $this->rooms->create();
        [$a, $b, $c] = $this->threeTeams($room);

        // Kolo: setki punktow.
        $this->scoreboard->add($room->code, $a->id, 900);
        $this->scoreboard->add($room->code, $b->id, 500);
        $this->scoreboard->add($room->code, $c->id, 100);

        $first = $this->tournament->recordCurrentGame($room, 'kolo');
        self::assertNotNull($first);
        self::assertSame([$a->id => 3, $b->id => 2, $c->id => 1], $first['points']);

        // Melodia: pojedyncze punkty, inna kolejnosc - ale waga ta sama.
        $this->scoreboard->add($room->code, $c->id, 3);
        $this->scoreboard->add($room->code, $a->id, 2);
        $this->scoreboard->add($room->code, $b->id, 1);

        $this->tournament->recordCurrentGame($room, 'melodia');

        $standings = $this->indexById($this->tournament->standings($room));
        // A: 3+2, C: 1+3, B: 2+1 - remis A i C na 5, ale liczy sie, ze skala
        // gry nie zdecydowala: melodia wazyla tyle samo co kolo.
        self::assertSame(5, $standings[$a->id]['total']);
        self::assertSame(4, $standings[$c->id]['total']);
        self::assertSame(3, $standings[$b->id]['total']);
    }

    /** Remis: obie druzyny dostaja punkty za wyzsze z zajmowanych miejsc. */
    public function testTiedTeamsShareTheHigherPlacement(): void
    {
        $room = $this->rooms->create();
        [$a, $b, $c] = $this->threeTeams($room);

        $this->scoreboard->add($room->code, $a->id, 50);
        $this->scoreboard->add($room->code, $b->id, 50);
        $this->scoreboard->add($room->code, $c->id, 10);

        $result = $this->tournament->recordCurrentGame($room, 'panstwa');
        self::assertNotNull($result);

        // A i B remisuja na 1. miejscu: obie po 3 (za pierwsze), C zostaje z 1.
        self::assertSame(3, $result['points'][$a->id]);
        self::assertSame(3, $result['points'][$b->id]);
        self::assertSame(1, $result['points'][$c->id]);
    }

    /** Po zaliczeniu do turnieju surowe punkty wracaja do zera pod nastepna gre. */
    public function testRawScoreResetsAfterRecording(): void
    {
        $room = $this->rooms->create();
        [$a, $b] = $this->threeTeams($room);

        $this->scoreboard->add($room->code, $a->id, 40);
        $this->scoreboard->add($room->code, $b->id, 20);

        $this->tournament->recordCurrentGame($room, 'panstwa');

        self::assertSame(0, $this->scoreboard->get($room->code, $a->id));
        self::assertSame(0, $this->scoreboard->get($room->code, $b->id));
    }

    /**
     * Gra bez ani jednego punktu (host zmienil gre od razu, albo to byl Dutch,
     * ktory tablicy nie dotyka) nie rozdaje miejsc - inaczej premiowalaby
     * druzyny za nic i zafalszowala turniej.
     */
    public function testGameWithoutPointsIsNotRecorded(): void
    {
        $room = $this->rooms->create();
        $this->threeTeams($room);

        self::assertNull($this->tournament->recordCurrentGame($room, 'dutch'));
        self::assertSame(0, $this->tournament->playedGames($room->code));
    }

    /** Turniej ma miejsce dla kazdej druzyny w pokoju - takze przed pierwsza gra. */
    public function testStandingsIncludeTeamsWithNoPointsYet(): void
    {
        $room = $this->rooms->create();
        [$a, $b, $c] = $this->threeTeams($room);

        $standings = $this->tournament->standings($room);
        self::assertCount(3, $standings);
        self::assertSame(0, $standings[0]['total']);
    }

    public function testAwardingBroadcastsStandingsToTheRoom(): void
    {
        $room = $this->rooms->create();
        [$a, $b] = $this->threeTeams($room);

        $this->scoreboard->add($room->code, $a->id, 30);
        $this->scoreboard->add($room->code, $b->id, 10);

        $this->tournament->recordCurrentGame($room, 'kolo');

        // Wśród rozgłoszeń (są też eventy SCORE) szukamy tego jednego o tabeli
        // turnieju. Nie zerujemy $this->published inline - to zawęziłoby jej typ
        // do pustej, bo mutuje ją domknięcie MockHuba spoza tej metody.
        $standings = null;

        foreach ($this->published as $update) {
            $payload = json_decode($update->getData(), true, 512, \JSON_THROW_ON_ERROR);

            if (\is_array($payload) && 'tournament_awarded' === ($payload['type'] ?? null)) {
                $standings = $payload['standings'] ?? null;
            }
        }

        // Rozgłoszenie turnieju poszło na cały pokój, z pełną tabelą pozycji.
        self::assertIsArray($standings);
        self::assertNotEmpty($standings);

        $top = $standings[0] ?? null;
        self::assertIsArray($top);
        self::assertSame($a->id, $top['id'] ?? null);
    }

    public function testClearEmptiesTheTable(): void
    {
        $room = $this->rooms->create();
        [$a, $b] = $this->threeTeams($room);
        $this->scoreboard->add($room->code, $a->id, 30);
        $this->scoreboard->add($room->code, $b->id, 10);
        $this->tournament->recordCurrentGame($room, 'kolo');

        self::assertSame(1, $this->tournament->playedGames($room->code));

        $this->tournament->clear($room->code);

        self::assertSame(0, $this->tournament->playedGames($room->code));
    }

    /**
     * @return array{0: \App\Core\Room\Team, 1: \App\Core\Room\Team, 2: \App\Core\Room\Team}
     */
    private function threeTeams(Room $room): array
    {
        return [
            $this->rooms->join($room->code, 'Kanapowcy'),
            $this->rooms->join($room->code, 'Dywanowi'),
            $this->rooms->join($room->code, 'Fotelowi'),
        ];
    }

    /**
     * @param list<array{id: string, name: string, color: string, total: int, games: list<array{type: string, label: string, points: int}>}> $standings
     *
     * @return array<string, array{id: string, name: string, color: string, total: int, games: list<array{type: string, label: string, points: int}>}>
     */
    private function indexById(array $standings): array
    {
        $byId = [];

        foreach ($standings as $row) {
            $byId[$row['id']] = $row;
        }

        return $byId;
    }
}
