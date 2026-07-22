<?php

declare(strict_types=1);

namespace App\Content;

/**
 * Blad w pliku talii. Komunikat wskazuje plik i numer pozycji, bo talie
 * pisze rodzina w edytorze tekstu, a nie programista w IDE.
 */
final class DeckSyncException extends \RuntimeException
{
}
