<?php

declare(strict_types=1);

namespace App\Core\Game;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class UnknownGameException extends BadRequestHttpException
{
    /** @param list<string> $known */
    public function __construct(string $type, array $known)
    {
        parent::__construct(sprintf(
            'Nie znam gry "%s". Dostepne: %s.',
            $type,
            [] === $known ? 'brak zarejestrowanych modulow' : implode(', ', $known),
        ));
    }
}
