<?php

declare(strict_types=1);

namespace App\Tests\Core\Turn;

use App\Core\Realtime\Publisher;
use App\Core\Redis\RedisReader;
use App\Core\Room\Team;
use App\Core\Turn\TurnManager;
use App\Tests\RedisTestCase;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

final class TurnManagerTest extends RedisTestCase
{
    private const ROOM = 'ABCD';
    private const TTL = 60;

    private TurnManager $turns;

    /** @var list<Update> */
    private array $published = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->published = [];

        $this->turns = new TurnManager(
            $this->redis,
            new RedisReader($this->redis),
            new Publisher(new MockHub(
                url: 'http://localhost/.well-known/mercure',
                jwtProvider: new StaticTokenProvider('test'),
                publisher: function (Update $update): string {
                    $this->published[] = $update;

                    return 'test-id';
                },
            )),
            self::TTL,
        );
    }

    public function testFirstTeamStarts(): void
    {
        $first = $this->turns->start(self::ROOM, $this->teams(3));

        self::assertSame('team-1', $first);
        self::assertSame('team-1', $this->turns->current(self::ROOM));
        self::assertTrue($this->turns->isCurrent(self::ROOM, 'team-1'));
        self::assertFalse($this->turns->isCurrent(self::ROOM, 'team-2'));
    }

    public function testTurnGoesRoundAndRound(): void
    {
        $this->turns->start(self::ROOM, $this->teams(3));

        self::assertSame('team-2', $this->turns->next(self::ROOM));
        self::assertSame('team-3', $this->turns->next(self::ROOM));
        self::assertSame('team-1', $this->turns->next(self::ROOM), 'Po ostatniej druzynie wraca pierwsza.');
        self::assertSame('team-2', $this->turns->next(self::ROOM));
    }

    public function testEveryHandoverIsAnnouncedToTheRoom(): void
    {
        $this->turns->start(self::ROOM, $this->teams(2));
        $mark = \count($this->published);

        $this->turns->next(self::ROOM);

        $updates = \array_slice($this->published, $mark);
        self::assertCount(1, $updates);

        $payload = json_decode($updates[0]->getData(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('turn', $payload['type']);
        self::assertSame('team-2', $payload['teamId']);
    }

    /** Host rozstrzyga spory przy stole - musi moc oddac ture komu chce. */
    public function testHostCanHandTheTurnToAnyTeam(): void
    {
        $this->turns->start(self::ROOM, $this->teams(3));

        self::assertTrue($this->turns->giveTo(self::ROOM, 'team-3'));
        self::assertSame('team-3', $this->turns->current(self::ROOM));

        // ...i kolejnosc leci dalej od tego miejsca, a nie od poczatku.
        self::assertSame('team-1', $this->turns->next(self::ROOM));
    }

    public function testHandingTheTurnToAStrangerIsRefused(): void
    {
        $this->turns->start(self::ROOM, $this->teams(2));

        self::assertFalse($this->turns->giveTo(self::ROOM, 'team-99'));
        self::assertSame('team-1', $this->turns->current(self::ROOM));
    }

    /**
     * Kolejnosc jest ustalana raz. Druzyna dolaczajaca w polowie gry nie moze
     * przestawic tury - inaczej wejscie spoznionego gosca zmienialoby czyja
     * kolej w srodku rundy.
     */
    public function testJoiningLaterDoesNotDisturbTheRunningOrder(): void
    {
        $this->turns->start(self::ROOM, $this->teams(2));
        $this->turns->next(self::ROOM);

        self::assertSame('team-2', $this->turns->current(self::ROOM));
        self::assertSame(['team-1', 'team-2'], $this->turns->order(self::ROOM));
    }

    public function testRestartingReshufflesTheOrderAndGoesBackToTheFirstTeam(): void
    {
        $this->turns->start(self::ROOM, $this->teams(3));
        $this->turns->next(self::ROOM);

        $first = $this->turns->start(self::ROOM, $this->teams(2));

        self::assertSame('team-1', $first);
        self::assertSame(['team-1', 'team-2'], $this->turns->order(self::ROOM));
        self::assertSame('team-1', $this->turns->current(self::ROOM));
    }

    public function testEmptyRoomHasNobodyToPlay(): void
    {
        self::assertNull($this->turns->start(self::ROOM, []));
        self::assertNull($this->turns->current(self::ROOM));
        self::assertNull($this->turns->next(self::ROOM));
    }

    public function testSingleTeamKeepsPlayingItself(): void
    {
        $this->turns->start(self::ROOM, $this->teams(1));

        self::assertSame('team-1', $this->turns->next(self::ROOM));
        self::assertSame('team-1', $this->turns->next(self::ROOM));
    }

    public function testRoomsDoNotShareTurns(): void
    {
        $this->turns->start(self::ROOM, $this->teams(3));
        $this->turns->next(self::ROOM);

        self::assertNull($this->turns->current('WXYZ'));
    }

    /** @return list<Team> */
    private function teams(int $count): array
    {
        $teams = [];

        for ($i = 1; $i <= $count; ++$i) {
            $teams[] = new Team('team-'.$i, 'Druzyna '.$i, '#e5484d', 'token-'.$i, $i, 1_700_000_000);
        }

        return $teams;
    }
}
