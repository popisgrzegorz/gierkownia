<?php

declare(strict_types=1);

namespace App\Core\Room;

/**
 * Kod pokoju przepisywany z telewizora na telefon, wiec alfabet bez znakow,
 * ktore myli sie z drugiej strony pokoju: I/1, O/0, S/5, Z/2.
 */
final class RoomCodeGenerator
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRTUVWXY';
    private const LENGTH = 4;

    public function generate(): string
    {
        $max = \strlen(self::ALPHABET) - 1;
        $code = '';

        for ($i = 0; $i < self::LENGTH; ++$i) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }
}
