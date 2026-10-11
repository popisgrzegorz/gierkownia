<?php

declare(strict_types=1);

namespace App\Tests\Game\OstatniaLitera;

use App\Game\OstatniaLitera\HunspellWordJudge;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Sedzia slow rozmawia z prawdziwym hunspellem - atrapa potwierdzalaby tylko,
 * ze umiemy pisac atrapy. Bez hunspella i slownika polskiego (apt install
 * hunspell hunspell-pl) test jest pomijany, a w CI oba pakiety sa instalowane.
 */
final class HunspellWordJudgeTest extends TestCase
{
    protected function setUp(): void
    {
        $probe = new Process(['hunspell', '-d', 'pl_PL', '-l']);

        try {
            $probe->setInput('test');
            $probe->run();
        } catch (\Throwable) {
            self::markTestSkipped('Brak hunspella w systemie.');
        }

        // Nieznany slownik hunspell zglasza bledem, nie pustym wyjsciem.
        if (!$probe->isSuccessful()) {
            self::markTestSkipped('Brak slownika pl_PL (apt install hunspell-pl).');
        }
    }

    public function testCommonPolishWordsAreKnown(): void
    {
        $judge = new HunspellWordJudge();

        foreach (['wiatr', 'ARBUZ', 'zebra', 'żółw', 'wąż'] as $word) {
            self::assertTrue($judge->isKnown($word), sprintf('Slownik powinien znac "%s".', $word));
        }
    }

    public function testGibberishIsNotKnown(): void
    {
        self::assertFalse((new HunspellWordJudge())->isKnown('WQZXV'));
    }
}
