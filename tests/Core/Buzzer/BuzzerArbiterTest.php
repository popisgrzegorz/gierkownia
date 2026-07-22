<?php

declare(strict_types=1);

namespace App\Tests\Core\Buzzer;

use App\Core\Buzzer\BuzzerArbiter;
use App\Core\Buzzer\BuzzResult;
use App\Core\Realtime\Publisher;
use App\Core\Redis\RedisReader;
use App\Core\Room\RoomKeys;
use App\Core\Room\Team;
use App\Tests\RedisTestCase;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

final class BuzzerArbiterTest extends RedisTestCase
{
    private const ROOM = 'ABCD';
    private const TTL = 60;

    private BuzzerArbiter $arbiter;
    private Team $kanapowcy;
    private Team $dywanowi;

    /** @var list<Update> */
    private array $published = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->published = [];

        $this->arbiter = new BuzzerArbiter(
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

        $this->kanapowcy = new Team('team-1', 'Kanapowcy', '#e5484d', 'token-1', 1, 1_700_000_000);
        $this->dywanowi = new Team('team-2', 'Dywanowi', '#3e63dd', 'token-2', 2, 1_700_000_000);
    }

    public function testArmingOpensARoundAndAnnouncesIt(): void
    {
        $round = $this->arbiter->arm(self::ROOM);

        self::assertSame(1, $round);
        self::assertTrue($this->arbiter->isArmed(self::ROOM));
        self::assertSame(1, $this->arbiter->armedRound(self::ROOM));

        $payload = $this->payloadAt(0);
        self::assertSame('armed', $payload['type']);
        self::assertSame(1, $payload['round']);
    }

    public function testRoundNumbersNeverRepeat(): void
    {
        self::assertSame(1, $this->arbiter->arm(self::ROOM));
        self::assertSame(2, $this->arbiter->arm(self::ROOM));
        self::assertSame(3, $this->arbiter->arm(self::ROOM));
    }

    public function testFirstBuzzWinsAndCarriesTheTeamOnScreen(): void
    {
        $round = $this->arbiter->arm(self::ROOM);

        $result = $this->arbiter->buzz(self::ROOM, $round, $this->kanapowcy);

        self::assertTrue($result->accepted);
        self::assertSame('team-1', $result->winnerTeamId);
        self::assertNull($result->reason);

        $payload = $this->payloadAt(1);
        self::assertSame('buzz', $payload['type']);
        self::assertSame('team-1', $payload['teamId']);
        self::assertSame('Kanapowcy', $payload['teamName']);
        self::assertSame(1, $payload['round']);
    }

    /**
     * Sedno lockoutu: drugi telefon dostaje odmowe wraz z informacja, kto wygral,
     * zeby moc od razu pokazac wlasciwy ekran zamiast czekac na broadcast.
     */
    public function testSecondBuzzInTheSameRoundIsLockedOut(): void
    {
        $round = $this->arbiter->arm(self::ROOM);
        $this->arbiter->buzz(self::ROOM, $round, $this->kanapowcy);

        $late = $this->arbiter->buzz(self::ROOM, $round, $this->dywanowi);

        self::assertFalse($late->accepted);
        self::assertSame(BuzzResult::REASON_LOCKED, $late->reason);
        self::assertSame('team-1', $late->winnerTeamId);
    }

    public function testLosingBuzzDoesNotOverwriteTheWinner(): void
    {
        $round = $this->arbiter->arm(self::ROOM);
        $this->arbiter->buzz(self::ROOM, $round, $this->kanapowcy);
        $this->arbiter->buzz(self::ROOM, $round, $this->dywanowi);

        self::assertSame('team-1', $this->arbiter->winner(self::ROOM, $round));
    }

    public function testLosingBuzzIsNotAnnounced(): void
    {
        $round = $this->arbiter->arm(self::ROOM);
        $this->arbiter->buzz(self::ROOM, $round, $this->kanapowcy);
        $mark = \count($this->published);

        $this->arbiter->buzz(self::ROOM, $round, $this->dywanowi);

        self::assertSame([], \array_slice($this->published, $mark), 'Przegrany buzz nie moze migac na ekranie hosta.');
    }

    public function testWinningBuzzClosesTheRound(): void
    {
        $round = $this->arbiter->arm(self::ROOM);
        $this->arbiter->buzz(self::ROOM, $round, $this->kanapowcy);

        self::assertFalse($this->arbiter->isArmed(self::ROOM));
    }

    public function testBuzzWithoutAnArmedRoundIsRefused(): void
    {
        $result = $this->arbiter->buzz(self::ROOM, 1, $this->kanapowcy);

        self::assertFalse($result->accepted);
        self::assertSame(BuzzResult::REASON_STALE, $result->reason);
    }

    /**
     * Klasyk z imprezy: ktos przycisnal ulamek sekundy po tym, jak host zamknal
     * runde. Zadanie nie moze zostac zaliczone do biezacej rundy, ale telefon
     * ma prawo uslyszec, kto wygral te, o ktora pytal.
     */
    public function testBuzzFromThePreviousRoundIsRefusedAndNamesItsWinner(): void
    {
        $first = $this->arbiter->arm(self::ROOM);
        $this->arbiter->buzz(self::ROOM, $first, $this->kanapowcy);
        $this->arbiter->arm(self::ROOM);

        $late = $this->arbiter->buzz(self::ROOM, $first, $this->dywanowi);

        self::assertFalse($late->accepted);
        self::assertSame(BuzzResult::REASON_LOCKED, $late->reason);
        self::assertSame('team-1', $late->winnerTeamId);
    }

    /** Runda przerwana przez hosta nie miala zwyciezcy - tu "stale" jest jedyna prawda. */
    public function testBuzzFromARoundNobodyWonIsStale(): void
    {
        $first = $this->arbiter->arm(self::ROOM);
        $this->arbiter->disarm(self::ROOM);
        $this->arbiter->arm(self::ROOM);

        $late = $this->arbiter->buzz(self::ROOM, $first, $this->dywanowi);

        self::assertFalse($late->accepted);
        self::assertSame(BuzzResult::REASON_STALE, $late->reason);
        self::assertNull($late->winnerTeamId);
    }

    public function testBuzzFromAFutureRoundIsRefused(): void
    {
        $this->arbiter->arm(self::ROOM);

        $result = $this->arbiter->buzz(self::ROOM, 99, $this->kanapowcy);

        self::assertFalse($result->accepted);
        self::assertSame(BuzzResult::REASON_STALE, $result->reason);
    }

    public function testDisarmStopsAcceptingBuzzesWithoutPickingAWinner(): void
    {
        $round = $this->arbiter->arm(self::ROOM);

        $this->arbiter->disarm(self::ROOM);
        $result = $this->arbiter->buzz(self::ROOM, $round, $this->kanapowcy);

        self::assertFalse($result->accepted);
        self::assertSame(BuzzResult::REASON_STALE, $result->reason);
        self::assertNull($this->arbiter->winner(self::ROOM, $round));
    }

    public function testNextRoundLetsTheLockedOutTeamWin(): void
    {
        $first = $this->arbiter->arm(self::ROOM);
        $this->arbiter->buzz(self::ROOM, $first, $this->kanapowcy);

        $second = $this->arbiter->arm(self::ROOM);
        $result = $this->arbiter->buzz(self::ROOM, $second, $this->dywanowi);

        self::assertTrue($result->accepted);
        self::assertSame('team-2', $this->arbiter->winner(self::ROOM, $second));
    }

    public function testRoomsDoNotShareTheirBuzzers(): void
    {
        $round = $this->arbiter->arm(self::ROOM);
        $this->arbiter->buzz(self::ROOM, $round, $this->kanapowcy);

        self::assertFalse($this->arbiter->isArmed('WXYZ'));
        self::assertNull($this->arbiter->winner('WXYZ', $round));
    }

    public function testBuzzerKeysExpireWithTheRoom(): void
    {
        $round = $this->arbiter->arm(self::ROOM);
        $this->arbiter->buzz(self::ROOM, $round, $this->kanapowcy);

        foreach ([RoomKeys::gameRound(self::ROOM), RoomKeys::gameWinner(self::ROOM, $round)] as $key) {
            self::assertGreaterThan(0, (int) $this->redis->ttl($key), sprintf('Klucz %s zostalby na zawsze.', $key));
        }
    }

    /** @return array<string, mixed> */
    private function payloadAt(int $index): array
    {
        self::assertArrayHasKey($index, $this->published, 'Nie opublikowano tylu eventow.');

        $payload = json_decode($this->published[$index]->getData(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        $typed = [];
        foreach ($payload as $key => $value) {
            if (\is_string($key)) {
                $typed[$key] = $value;
            }
        }

        return $typed;
    }
}
