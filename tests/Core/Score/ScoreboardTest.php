<?php

declare(strict_types=1);

namespace App\Tests\Core\Score;

use App\Core\Realtime\Publisher;
use App\Core\Redis\RedisReader;
use App\Core\Room\RoomCodeGenerator;
use App\Core\Room\RoomManager;
use App\Core\Score\Scoreboard;
use App\Tests\RedisTestCase;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

final class ScoreboardTest extends RedisTestCase
{
    private const TTL = 60;

    private RoomManager $rooms;
    private Scoreboard $scoreboard;

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
        $this->rooms = new RoomManager($this->redis, $reader, new RoomCodeGenerator(), self::TTL);
        $this->scoreboard = new Scoreboard($this->redis, $reader, $this->rooms, new Publisher($hub), self::TTL);
    }

    public function testPointsAccumulateAndCanGoDown(): void
    {
        $room = $this->rooms->create();
        $team = $this->rooms->join($room->code, 'Kanapowcy');

        self::assertSame(1, $this->scoreboard->add($room->code, $team->id, 1));
        self::assertSame(3, $this->scoreboard->add($room->code, $team->id, 2));
        self::assertSame(2, $this->scoreboard->add($room->code, $team->id, -1));
        self::assertSame(2, $this->scoreboard->get($room->code, $team->id));
    }

    /**
     * Host klika szybciej niz leci odpowiedz. Gdyby punktowanie szlo przez
     * odczyt-modyfikacja-zapis, rownolegle zadania nadpisalyby sie nawzajem
     * i czesc punktow znikalaby bez sladu.
     */
    public function testConcurrentIncrementsAreNotLost(): void
    {
        $room = $this->rooms->create();
        $team = $this->rooms->join($room->code, 'Kanapowcy');

        for ($i = 0; $i < 50; ++$i) {
            $this->scoreboard->add($room->code, $team->id, 1);
        }

        self::assertSame(50, $this->scoreboard->get($room->code, $team->id));
    }

    public function testEveryChangeIsAnnouncedToTheRoom(): void
    {
        $room = $this->rooms->create();
        $team = $this->rooms->join($room->code, 'Kanapowcy');

        $this->scoreboard->add($room->code, $team->id, 3);

        self::assertCount(1, $this->published);

        $update = $this->published[0];
        $payload = json_decode($update->getData(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        self::assertSame(['room/'.$room->code], $update->getTopics());
        self::assertFalse($update->isPrivate(), 'Tablo jest jawne dla calego pokoju.');
        self::assertSame('score', $payload['type']);
        self::assertSame($team->id, $payload['teamId']);
        self::assertSame(3, $payload['total']);
    }

    public function testBoardListsEveryTeamInJoinOrder(): void
    {
        $room = $this->rooms->create();
        $first = $this->rooms->join($room->code, 'Kanapowcy');
        $second = $this->rooms->join($room->code, 'Dywanowi');

        $this->scoreboard->add($room->code, $second->id, 5);

        self::assertSame([$first->id => 0, $second->id => 5], $this->scoreboard->all($room->code));
    }

    public function testBoardOfAnEmptyRoomIsEmpty(): void
    {
        $room = $this->rooms->create();

        self::assertSame([], $this->scoreboard->all($room->code));
    }
}
