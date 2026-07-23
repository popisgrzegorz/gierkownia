<?php

declare(strict_types=1);

namespace App\Tests\Game\MelodiaQuiz;

use App\Game\MelodiaQuiz\Wybory;
use PHPUnit\Framework\TestCase;

/**
 * Zestaw odpowiedzi do testu 1 z 4. Jedyne miejsce, w ktorym da sie zepsuc
 * pytanie tak, ze nikt tego nie zauwazy przy stole.
 */
final class WyboryTest extends TestCase
{
    public function testCorrectTitleIsAlwaysAmongTheOptions(): void
    {
        $result = Wybory::build('Sto lat', ['Panie Janie', 'Wlazł kotek', 'Krakowiaczek', 'Zielona łódka']);

        self::assertCount(4, $result['options']);
        self::assertSame('Sto lat', $result['options'][$result['correct']]);
    }

    /** Dwa razy ten sam tytul to pytanie bez poprawnej odpowiedzi. */
    public function testOptionsNeverRepeat(): void
    {
        $result = Wybory::build('Sto lat', ['Panie Janie', 'panie janie', 'Sto lat', 'Wlazł kotek', 'Krakowiaczek']);

        self::assertSame($result['options'], array_values(array_unique($result['options'])));
    }

    /**
     * Poprawna odpowiedz nie moze siedziec zawsze pod ta sama litera - inaczej
     * po dwoch rundach wszyscy klikaliby w ciemno to samo miejsce.
     */
    public function testCorrectAnswerDoesNotSitInOnePlace(): void
    {
        $seen = [];

        for ($i = 0; $i < 60; ++$i) {
            $seen[Wybory::build('Sto lat', ['A', 'B', 'C', 'D', 'E'])['correct']] = true;
        }

        self::assertGreaterThan(1, \count($seen), 'Poprawna odpowiedź trafiła zawsze w to samo miejsce.');
    }

    /** Za malo nagran w kategorii - modul ma to zauwazyc, a nie dolozyc pustych. */
    public function testTooFewTitlesGiveTooFewOptions(): void
    {
        $result = Wybory::build('Sto lat', ['Panie Janie']);

        self::assertCount(2, $result['options']);
        self::assertSame('Sto lat', $result['options'][$result['correct']]);
    }
}
