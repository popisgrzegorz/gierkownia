<?php

declare(strict_types=1);

namespace App\Core\Room;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Nazwa jest juz zajeta w tym pokoju, a telefon nie ma tokenu zadnej druzyny
 * z tego pokoju (patrz RoomManager::join) - to nie powrot po utraconym
 * ciasteczku, tylko probka przejecia cudzej druzyny wpisaniem jej nazwy.
 */
final class TeamNameTakenException extends ConflictHttpException
{
    public function __construct(string $name)
    {
        parent::__construct(sprintf('Nazwa "%s" jest juz zajeta w tym pokoju.', $name));
    }
}
