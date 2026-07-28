<?php

declare(strict_types=1);

namespace App\Core\Tournament;

/** Zapisany turniej o tym id nie istnieje (skasowany plik albo zly link). */
final class TournamentNotFoundException extends \RuntimeException
{
    public function __construct(string $id)
    {
        parent::__construct(sprintf('Nie ma zapisanego turnieju o id "%s".', $id));
    }
}
