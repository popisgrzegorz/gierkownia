<?php

declare(strict_types=1);

namespace App\Tests\Core\Tournament;

use App\Core\Game\GameRegistry;
use App\Core\Realtime\Publisher;
use App\Core\Redis\RedisReader;
use App\Core\Room\RoomCodeGenerator;
use App\Core\Room\RoomManager;
use App\Core\Score\Scoreboard;
use App\Core\Tournament\Tournament;
use App\Core\Tournament\TournamentArchive;
use App\Core\Tournament\TournamentNotFoundException;
use App\Tests\RedisConnection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

/**
 * Zapis turnieju na dysk i wznowienie w nowym pokoju - "wróćmy do tego jutro".
 */
final class TournamentArchiveTest extends TestCase
{
    use RedisConnection;

    private const TTL = 60;

    private RoomManager $rooms;
    private Scoreboard $scoreboard;
    private Tournament $tournament;
    private TournamentArchive $archive;
    private string $dir;

    protected function setUp(): void
    {
        $this->connectRedis();

        $hub = new MockHub(
            url: 'http://localhost/.well-known/mercure',
            jwtProvider: new StaticTokenProvider('test'),
            publisher: static fn (Update $update): string => 'test-id',
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

        $this->dir = sys_get_temp_dir().'/gierki-turnieje-'.bin2hex(random_bytes(4));
        $this->archive = new TournamentArchive($this->rooms, $this->tournament, $this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*.json') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    /**
     * Sedno wznowienia: po wczytaniu do nowego pokoju gracz wraca pod ta sama
     * nazwa i odzyskuje swoj dorobek w turnieju - punkty za miejsca sa te same,
     * bo id druzyny zostalo zachowane.
     */
    public function testSavedTournamentIsRestoredIntoANewRoomWithPointsIntact(): void
    {
        $room = $this->rooms->create();
        $kasia = $this->rooms->join($room->code, 'Kasia i Tomek');
        $ania = $this->rooms->join($room->code, 'Ania i Piotr');

        // Rozegrana gra: Kasia wygrywa, wiec ma 2, Ania 1.
        $this->scoreboard->add($room->code, $kasia->id, 900);
        $this->scoreboard->add($room->code, $ania->id, 100);
        $this->tournament->recordCurrentGame($room, 'kolo');

        $id = $this->archive->save($room, 'Wieczór u Kasi');

        // Nastepnego dnia: pokoju z Redisa juz nie ma (TTL), wznawiamy z pliku.
        $this->redis->flushdb();

        $restored = $this->archive->restore($id);
        self::assertNotSame($room->code, $restored->code, 'Wznowienie zaklada nowy pokoj.');

        // Wracajacy telefon wpisuje te sama nazwe - join() oddaje mu druzyne.
        $kasiaBack = $this->rooms->join($restored->code, 'Kasia i Tomek');
        self::assertSame($kasia->id, $kasiaBack->id, 'Ta sama nazwa = ta sama druzyna z dorobkiem.');

        $standings = $this->tournament->standings($restored);
        $byId = [];
        foreach ($standings as $row) {
            $byId[$row['id']] = $row['total'];
        }

        self::assertSame(2, $byId[$kasia->id]);
        self::assertSame(1, $byId[$ania->id]);
    }

    /** Nastepni dosiadajacy sie po wznowieniu dostaja kolejne numery, nie duble. */
    public function testNewTeamAfterRestoreGetsTheNextSeat(): void
    {
        $room = $this->rooms->create();
        $this->rooms->join($room->code, 'Kasia i Tomek');
        $this->rooms->join($room->code, 'Ania i Piotr');
        $this->scoreboard->add($room->code, $this->rooms->teams($room->code)[0]->id, 10);
        $this->tournament->recordCurrentGame($room, 'kolo');
        $id = $this->archive->save($room, 'Wieczór');
        $this->redis->flushdb();

        $restored = $this->archive->restore($id);
        $newcomer = $this->rooms->join($restored->code, 'Spóźnialscy');

        self::assertSame(3, $newcomer->seq, 'Trzecia druzyna dostaje numer 3, nie zdublowany.');
    }

    public function testListSummarisesSavedTournamentsNewestFirst(): void
    {
        $room = $this->rooms->create();
        $a = $this->rooms->join($room->code, 'Alfa');
        $this->rooms->join($room->code, 'Beta');
        $this->scoreboard->add($room->code, $a->id, 50);
        $this->tournament->recordCurrentGame($room, 'kolo');

        $this->archive->save($room, 'Pierwszy');
        $this->archive->save($room, 'Drugi');

        $list = $this->archive->list();
        self::assertCount(2, $list);
        self::assertSame(2, $list[0]['teamCount']);
        self::assertSame('Alfa', $list[0]['leader']);
    }

    public function testRestoringAMissingTournamentFails(): void
    {
        $this->expectException(TournamentNotFoundException::class);

        $this->archive->restore('brakTakiego');
    }
}
