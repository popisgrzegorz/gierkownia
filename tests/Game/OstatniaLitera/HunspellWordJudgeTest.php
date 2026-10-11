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
    private string|false $previousLcAll = false;

    protected function setUp(): void
    {
        // Odtwarzamy srodowisko PHP-FPM (LANG=C), bo to w nim hunspell ciał słowa
        // na polskich literach. Runner CI ma zwykle UTF-8 i bez tego test
        // przechodziłby także bez poprawki.
        $this->previousLcAll = getenv('LC_ALL');
        putenv('LC_ALL=C');

        $probe = new Process(['hunspell', '-d', 'pl_PL', '-i', 'utf-8', '-l']);

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

    protected function tearDown(): void
    {
        false === $this->previousLcAll ? putenv('LC_ALL') : putenv('LC_ALL='.$this->previousLcAll);

        parent::tearDown();
    }

    public function testCommonPolishWordsAreKnown(): void
    {
        $judge = new HunspellWordJudge();

        // "straż" i "STRAŻ": przy lokalizacji C hunspell tnie na ż i sprawdza "stra".
        foreach (['wiatr', 'ARBUZ', 'zebra', 'żółw', 'wąż', 'straż', 'STRAŻ', 'gęślą', 'jaźń'] as $word) {
            self::assertTrue($judge->isKnown($word), sprintf('Slownik powinien znac "%s".', $word));
        }
    }

    public function testGibberishIsNotKnown(): void
    {
        $judge = new HunspellWordJudge();

        self::assertFalse($judge->isKnown('WQZXV'));
        // Bez -i utf-8 "qżż" sprawdzalo sie jako samo "q" i tez mogloby przejsc.
        self::assertFalse($judge->isKnown('qżż'));
        self::assertFalse($judge->isKnown('QŹĆŻŃĘ'));
    }
}
