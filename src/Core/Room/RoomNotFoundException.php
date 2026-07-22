<?php

declare(strict_types=1);

namespace App\Core\Room;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Pokoj wygasl (TTL) albo kod zostal zle przepisany z ekranu.
 *
 * Dziedziczy po NotFoundHttpException, zeby kontrolery nie musialy tlumaczyc
 * tego na 404 przy kazdym wywolaniu.
 */
final class RoomNotFoundException extends NotFoundHttpException
{
    public function __construct(string $code)
    {
        parent::__construct(sprintf('Pokoj "%s" nie istnieje lub juz wygasl.', $code));
    }
}
