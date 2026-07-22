<?php

declare(strict_types=1);

namespace App\Tests\Core\Timer;

use App\Core\Redis\RedisReader;
use App\Core\Timer\GameTimer;
use App\Tests\RedisTestCase;

final class GameTimerTest extends RedisTestCase
{
    private GameTimer $timer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->timer = new GameTimer($this->redis, new RedisReader($this->redis), 3600);
    }

    /**
     * Termin razem z czasem serwera. Bez serverNow telefon z przestawionym
     * zegarem odmierzylby zupelnie inny czas, a licznik oglada naraz telewizor
     * i wszystkie telefony - rozjazd byloby widac przy stole.
     */
    public function testStartReturnsDeadlineAlongsideServerClock(): void
    {
        $started = $this->timer->start('ABCD', 60);

        self::assertSame(60, $started['seconds']);
        self::assertSame(60_000, $started['deadline'] - $started['serverNow']);
    }

    public function testRemainingCountsDownAndNeverGoesNegative(): void
    {
        $this->timer->start('ABCD', 60);
        self::assertGreaterThan(59_000, $this->timer->remaining('ABCD'));

        // Termin w przeszlosci: runda dawno minela.
        $this->timer->start('EFGH', 1);
        $this->redis->setex('room:EFGH:game:deadline', 3600, (string) ((int) (microtime(true) * 1000) - 5_000));

        self::assertSame(0, $this->timer->remaining('EFGH'));
        self::assertTrue($this->timer->expired('EFGH'));
        self::assertFalse($this->timer->running('EFGH'));
    }

    /** Brak odliczania to nie to samo co czas, ktory minal. */
    public function testNoTimerIsNotAnExpiredTimer(): void
    {
        self::assertNull($this->timer->deadline('ABCD'));
        self::assertFalse($this->timer->expired('ABCD'));
        self::assertSame(0, $this->timer->remaining('ABCD'));
    }

    public function testClearStopsTheCountdown(): void
    {
        $this->timer->start('ABCD', 60);
        $this->timer->clear('ABCD');

        self::assertNull($this->timer->deadline('ABCD'));
    }

    public function testRefusesACountdownShorterThanASecond(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->timer->start('ABCD', 0);
    }
}
