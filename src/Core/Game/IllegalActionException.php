<?php

declare(strict_types=1);

namespace App\Core\Game;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Akcja niedozwolona: zla rola, zly stan gry albo nieznana nazwa akcji.
 *
 * To nie jest blad serwera - najczesciej znaczy, ze telefon wyslal zadanie
 * na podstawie nieaktualnego ekranu (np. buzz po zakonczeniu rundy).
 */
final class IllegalActionException extends BadRequestHttpException
{
}
