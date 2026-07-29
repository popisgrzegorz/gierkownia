<?php

declare(strict_types=1);

namespace App\Tests\Core\Room;

use App\Core\Redis\RedisReader;
use App\Core\Room\RoomCodeGenerator;
use App\Core\Room\RoomKeys;
use App\Core\Room\RoomManager;
use App\Core\Room\RoomNotFoundException;
use App\Core\Room\TeamNameTakenException;
use App\Tests\RedisTestCase;

final class RoomManagerTest extends RedisTestCase
{
    private const TTL = 60;

    private RoomManager $rooms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rooms = new RoomManager($this->redis, new RedisReader($this->redis), new RoomCodeGenerator(), self::TTL);
    }

    public function testCreatedRoomIsReadableByItsCode(): void
    {
        $room = $this->rooms->create('melodia');

        self::assertMatchesRegularExpression('/^[A-Z]{4}$/', $room->code);
        self::assertNotSame('', $room->hostToken);

        $loaded = $this->rooms->getOrFail($room->code);

        self::assertSame($room->code, $loaded->code);
        self::assertSame('melodia', $loaded->gameType);
        self::assertSame('lobby', $loaded->phase);
    }

    public function testRoomKeysCarryTtlSoTheyCleanUpAfterTheParty(): void
    {
        $room = $this->rooms->create();
        $this->rooms->join($room->code, 'Kanapowcy');

        foreach ([RoomKeys::meta($room->code), RoomKeys::teams($room->code)] as $key) {
            self::assertGreaterThan(0, (int) $this->redis->ttl($key), sprintf('Klucz %s zostalby na zawsze.', $key));
        }
    }

    public function testCodesDoNotCollide(): void
    {
        $codes = [];

        for ($i = 0; $i < 60; ++$i) {
            $codes[] = $this->rooms->create()->code;
        }

        self::assertSame($codes, array_unique($codes), 'Dwa pokoje dostaly ten sam kod.');
    }

    public function testMissingRoomThrows(): void
    {
        $this->expectException(RoomNotFoundException::class);

        $this->rooms->getOrFail('ZZZZ');
    }

    /**
     * setPhase/setGameType byly wolane przy KAZDEJ akcji gry, ale nigdy nie
     * przedluzaly TTL meta - liczyl sie wylacznie czas od ostatniego pelnego
     * zaladowania ekranu hosta. Dluga gra bez odswiezenia strony (SSE trzyma
     * ekran zywy bez przeladowania) mogla wygasnac w polowie rozgrywki.
     */
    public function testSetPhaseRefreshesTheMetaTtl(): void
    {
        $room = $this->rooms->create();
        $key = RoomKeys::meta($room->code);

        // Skracamy TTL recznie, zeby zasymulowac "dawno nieodswiezany" pokoj -
        // bez czekania naprawde 60 sekund w tescie.
        $this->redis->expire($key, 5);
        self::assertSame(5, (int) $this->redis->ttl($key));

        $this->rooms->setPhase($room->code, 'writing');

        self::assertSame(self::TTL, (int) $this->redis->ttl($key));
    }

    public function testSetGameTypeAlsoRefreshesTheMetaTtl(): void
    {
        $room = $this->rooms->create();
        $key = RoomKeys::meta($room->code);
        $this->redis->expire($key, 5);

        $this->rooms->setGameType($room->code, 'kolo');

        self::assertSame(self::TTL, (int) $this->redis->ttl($key));
    }

    /**
     * Klucz meta, ktory juz wygasl (albo zniknal recznym DEL), nie moze zostac
     * po cichu odtworzony przez hset z jednym polem - to dawalo hash bez
     * code/hostToken, na ktorym Room::fromRedisHash wywalal sie trwale przy
     * KAZDYM kolejnym zadaniu. Czysty RoomNotFoundException to oczekiwane,
     * naprawialne "pokoj wygasl", nie cichy, permanentny 500.
     */
    public function testSetPhaseOnAGoneRoomThrowsInsteadOfRecreatingABrokenHash(): void
    {
        $room = $this->rooms->create();
        $this->redis->del([RoomKeys::meta($room->code)]);

        $this->expectException(RoomNotFoundException::class);

        $this->rooms->setPhase($room->code, 'writing');
    }

    public function testSetGameTypeOnAGoneRoomThrows(): void
    {
        $room = $this->rooms->create();
        $this->redis->del([RoomKeys::meta($room->code)]);

        $this->expectException(RoomNotFoundException::class);

        $this->rooms->setGameType($room->code, 'kolo');
    }

    /**
     * Telefon z padnieta bateria wraca pod ta sama nazwa - musi odzyskac swoja
     * druzyne, a nie zalozyc obok drugiej o tej samej nazwie i zerowych punktach.
     */
    public function testRejoiningUnderTheSameNameReturnsTheSameTeam(): void
    {
        $room = $this->rooms->create();

        $first = $this->rooms->join($room->code, 'Kanapowcy');
        $again = $this->rooms->join($room->code, 'kanapowcy', knownDevice: true);

        self::assertSame($first->id, $again->id);
        self::assertSame($first->deviceToken, $again->deviceToken);
        self::assertCount(1, $this->rooms->teams($room->code));
    }

    /**
     * Bez dowodu, ze to urzadzenie juz mialo tu jakas druzyne, wpisanie cudzej
     * nazwy nie moze cicho oddac tokenu do niej - to byloby przejecie druzyny.
     */
    public function testJoiningWithATakenNameWithoutAKnownDeviceIsRejected(): void
    {
        $room = $this->rooms->create();
        $this->rooms->join($room->code, 'Kanapowcy');

        $this->expectException(TeamNameTakenException::class);

        $this->rooms->join($room->code, 'kanapowcy');
    }

    public function testTeamsGetDistinctColorsAndKeepJoinOrder(): void
    {
        $room = $this->rooms->create();

        foreach (['Kanapowcy', 'Dywanowi', 'Zaslonki'] as $name) {
            $this->rooms->join($room->code, $name);
        }

        $teams = $this->rooms->teams($room->code);

        self::assertSame(['Kanapowcy', 'Dywanowi', 'Zaslonki'], array_map(static fn ($t) => $t->name, $teams));
        self::assertCount(3, array_unique(array_map(static fn ($t) => $t->color, $teams)));
    }

    public function testEmptyTeamNameIsRejected(): void
    {
        $room = $this->rooms->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->rooms->join($room->code, '   ');
    }

    public function testDeviceTokenIdentifiesTheTeamAndNothingElse(): void
    {
        $room = $this->rooms->create();
        $team = $this->rooms->join($room->code, 'Kanapowcy');

        self::assertSame($team->id, $this->rooms->teamByDeviceToken($room->code, $team->deviceToken)?->id);
        self::assertNull($this->rooms->teamByDeviceToken($room->code, 'podrobiony'));
        self::assertNull($this->rooms->teamByDeviceToken($room->code, ''));
    }

    public function testHostTokenIsCheckedAgainstTheRoomItWasIssuedFor(): void
    {
        $room = $this->rooms->create();
        $other = $this->rooms->create();

        self::assertTrue($this->rooms->isHostToken($room->code, $room->hostToken));
        self::assertFalse($this->rooms->isHostToken($room->code, $other->hostToken));
        self::assertFalse($this->rooms->isHostToken($room->code, ''));
    }

    public function testResetClearsGameStateButKeepsTeamsInTheRoom(): void
    {
        $room = $this->rooms->create();
        $team = $this->rooms->join($room->code, 'Kanapowcy');

        $this->redis->set(RoomKeys::gameArmed($room->code), '7');
        $this->redis->set(RoomKeys::score($room->code, $team->id), '15');

        $this->rooms->resetGame($room->code);

        self::assertCount(1, $this->rooms->teams($room->code), 'Druzyny musialyby skanowac QR od nowa.');
        self::assertSame(0, (int) $this->redis->exists(RoomKeys::gameArmed($room->code)));
        self::assertSame('0', (string) $this->redis->get(RoomKeys::score($room->code, $team->id)));
    }

    public function testDestroyRemovesEverythingBelongingToTheRoom(): void
    {
        $room = $this->rooms->create();
        $this->rooms->join($room->code, 'Kanapowcy');

        $this->rooms->destroy($room->code);

        self::assertFalse($this->rooms->exists($room->code));
        self::assertSame([], $this->redis->keys(RoomKeys::allPattern($room->code)));
    }
}
