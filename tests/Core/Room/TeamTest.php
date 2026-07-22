<?php

declare(strict_types=1);

namespace App\Tests\Core\Room;

use App\Core\Room\Team;
use PHPUnit\Framework\TestCase;

final class TeamTest extends TestCase
{
    /**
     * Broadcast Mercure trafia do kazdego telefonu w pokoju. Gdyby niosl
     * deviceToken, dowolny gracz podszylby sie pod cudza druzyne - a przy
     * buzzerze to znaczy kradziez punktow.
     */
    public function testPublicViewNeverCarriesTheDeviceToken(): void
    {
        $team = new Team('id-1', 'Kanapowcy', '#e5484d', 'sekretny-token', 1, 1_700_000_000);

        self::assertStringNotContainsString(
            'sekretny-token',
            json_encode($team->toPublicArray(), \JSON_THROW_ON_ERROR),
        );

        // Kontrola dla powyzszego: pelna serializacja (ta do Redisa) token niesie,
        // wiec test faktycznie rozroznia oba widoki, a nie przechodzi przypadkiem.
        self::assertStringContainsString('sekretny-token', $team->toJson());
    }

    public function testSurvivesRoundTripThroughRedis(): void
    {
        $team = new Team('id-1', 'Kanapowcy', '#e5484d', 'token', 3, 1_700_000_000);

        $restored = Team::fromJson($team->toJson());

        self::assertEquals($team, $restored);
    }
}
