<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Game\OstatniaLitera\WordJudge;

/**
 * Atrapa slownika dla testow Ostatniej litery - stala lista "znanych" slow,
 * zero zaleznosci od zainstalowanego hunspella na maszynie uruchamiajacej testy.
 */
final readonly class FixedWordJudge implements WordJudge
{
    /** @param list<string> $known */
    public function __construct(private array $known)
    {
    }

    public function isKnown(string $word): bool
    {
        return \in_array(
            mb_strtolower($word),
            array_map(static fn (string $w): string => mb_strtolower($w), $this->known),
            true,
        );
    }
}
