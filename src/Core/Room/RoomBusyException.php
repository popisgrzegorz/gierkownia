<?php

declare(strict_types=1);

namespace App\Core\Room;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Nie udalo sie przejac zamka pokoju w rozsadnym czasie.
 *
 * W normalnej grze to nie powinno sie zdarzac - RoomLock czeka do WAIT_TIMEOUT_MS
 * na zwolnienie, a akcje trwaja pojedyncze milisekundy. Zobaczenie tego bledu
 * znaczy realny zator (np. zawieszone zadanie trzymajace zamek caly TTL) albo
 * blad w kodzie, ktory zapomnial zwolnic zamek.
 */
final class RoomBusyException extends HttpException
{
    public function __construct(string $code)
    {
        parent::__construct(409, sprintf(
            'Pokoj "%s" jest akurat zajety inna akcja - sprobuj ponownie za chwile.',
            $code,
        ));
    }
}
