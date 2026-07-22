<?php

declare(strict_types=1);

namespace App\Core\Game;

enum ActorRole: string
{
    case Host = 'host';
    case Team = 'team';
}
