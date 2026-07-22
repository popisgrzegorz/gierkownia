<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Baza dla testow dotykajacych stanu pokoju.
 *
 * Celowo bez mockowania Redisa: to wlasnie jego atomowosc (SET NX, INCRBY)
 * jest tym, co testujemy - atrapa potwierdzalaby tylko, ze umiemy pisac atrapy.
 */
abstract class RedisTestCase extends TestCase
{
    use RedisConnection;

    protected function setUp(): void
    {
        $this->connectRedis();
    }
}
