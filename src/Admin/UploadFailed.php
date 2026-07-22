<?php

declare(strict_types=1);

namespace App\Admin;

/**
 * Blad wgrywania pliku z komunikatem gotowym do pokazania w panelu.
 * Tresci dodaje rodzina, nie administrator - "UPLOAD_ERR_INI_SIZE" nikomu
 * nie pomoze, a "plik jest wiekszy niz limit serwera" juz tak.
 */
final class UploadFailed extends \RuntimeException
{
}
