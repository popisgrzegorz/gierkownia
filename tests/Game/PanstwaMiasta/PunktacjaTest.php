<?php

declare(strict_types=1);

namespace App\Tests\Game\PanstwaMiasta;

use App\Game\PanstwaMiasta\Punktacja;
use PHPUnit\Framework\TestCase;

/**
 * Liczenie punktow za kartke. To jedyne miejsce w tej grze, w ktorym da sie
 * skrzywdzic konkretna druzyne - a przy stole nikt nie bedzie przegladal
 * Redisa, zeby udowodnic swoje.
 */
final class PunktacjaTest extends TestCase
{
    private const COLUMNS = ['Państwo', 'Miasto'];

    public function testUniqueAnswerIsWorthTenAndSharedOneFive(): void
    {
        $result = Punktacja::score('K', self::COLUMNS, [
            'a' => ['Państwo' => 'Kanada', 'Miasto' => 'Kraków'],
            'b' => ['Państwo' => 'Kenia', 'Miasto' => 'Kraków'],
        ], []);

        self::assertSame(Punktacja::UNIQUE, $result['cells']['a']['Państwo']['points']);
        self::assertSame(Punktacja::UNIQUE, $result['cells']['b']['Państwo']['points']);
        self::assertSame(Punktacja::SHARED, $result['cells']['a']['Miasto']['points']);
        self::assertSame(15, $result['totals']['a']);
        self::assertSame(15, $result['totals']['b']);
    }

    /** Puste pole to zero - i nie ma czego uznawac. */
    public function testEmptyFieldScoresNothing(): void
    {
        $result = Punktacja::score('K', self::COLUMNS, [
            'a' => ['Państwo' => 'Kanada', 'Miasto' => ''],
        ], []);

        self::assertSame(0, $result['cells']['a']['Miasto']['points']);
        self::assertSame(Punktacja::STATUS_EMPTY, $result['cells']['a']['Miasto']['status']);
        self::assertSame(10, $result['totals']['a']);
    }

    /** Odpowiedz na inna litere nie liczy sie, dopoki host jej nie uzna. */
    public function testWrongLetterScoresNothing(): void
    {
        $result = Punktacja::score('K', self::COLUMNS, [
            'a' => ['Państwo' => 'Belgia', 'Miasto' => 'Kielce'],
        ], []);

        self::assertSame(0, $result['cells']['a']['Państwo']['points']);
        self::assertSame(Punktacja::STATUS_WRONG_LETTER, $result['cells']['a']['Państwo']['status']);
    }

    /**
     * Sedno korekty przy stole: literowka w pierwszej literze ("Ganada")
     * przechodzi jednym kliknieciem hosta, zamiast konczyc sie klotnia.
     */
    public function testHostCanAcceptATypo(): void
    {
        $answers = ['a' => ['Państwo' => 'Ganada', 'Miasto' => '']];

        $before = Punktacja::score('K', self::COLUMNS, $answers, []);
        $after = Punktacja::score('K', self::COLUMNS, $answers, ['a' => ['Państwo' => true]]);

        self::assertSame(0, $before['totals']['a']);
        self::assertSame(10, $after['totals']['a']);
        self::assertTrue($after['cells']['a']['Państwo']['forced']);
    }

    /** I odwrotnie: wymyslone slowo host odrzuca, mimo poprawnej litery. */
    public function testHostCanRejectANonsenseWord(): void
    {
        $result = Punktacja::score('K', self::COLUMNS, [
            'a' => ['Państwo' => 'Kanadia', 'Miasto' => ''],
        ], ['a' => ['Państwo' => false]]);

        self::assertSame(0, $result['totals']['a']);
        self::assertSame(Punktacja::STATUS_REJECTED, $result['cells']['a']['Państwo']['status']);
    }

    /**
     * Odrzucona odpowiedz przestaje tez byc duplikatem - inaczej druzyna
     * ukarana za wymyslone slowo ciagnelaby sasiada w dol razem ze soba.
     */
    public function testRejectedAnswerStopsCountingAsDuplicate(): void
    {
        $result = Punktacja::score('K', self::COLUMNS, [
            'a' => ['Państwo' => 'Kanada', 'Miasto' => ''],
            'b' => ['Państwo' => 'Kanada', 'Miasto' => ''],
        ], ['b' => ['Państwo' => false]]);

        self::assertSame(Punktacja::UNIQUE, $result['cells']['a']['Państwo']['points']);
        self::assertSame(0, $result['cells']['b']['Państwo']['points']);
    }

    /**
     * "Łódź", "Lodz" i "łódź" to przy stole jedno miasto. Gdyby liczyly sie
     * osobno, kazda druzyna dostawalaby 10 punktow za to samo slowo.
     */
    public function testSpellingDifferencesCountAsTheSameWord(): void
    {
        $result = Punktacja::score('L', ['Miasto'], [
            'a' => ['Miasto' => 'Łódź'],
            'b' => ['Miasto' => 'lodz'],
            'c' => ['Miasto' => '  Łodz '],
        ], []);

        self::assertSame(Punktacja::SHARED, $result['cells']['a']['Miasto']['points']);
        self::assertSame(Punktacja::SHARED, $result['cells']['b']['Miasto']['points']);
        self::assertSame(Punktacja::SHARED, $result['cells']['c']['Miasto']['points']);
    }

    /** Litera z ogonkiem w odpowiedzi nie moze wywrocic porownania. */
    public function testPolishLetterAtTheStartIsMatched(): void
    {
        $result = Punktacja::score('L', ['Miasto'], [
            'a' => ['Miasto' => 'Łomża'],
        ], []);

        self::assertSame(Punktacja::UNIQUE, $result['cells']['a']['Miasto']['points']);
    }

    /**
     * Jedna litera to nie odpowiedz. Zdarza sie, gdy ktos nie zdazy dopisac
     * slowa przed STOP-em albo wstukuje cokolwiek dla punktu.
     */
    public function testASingleLetterIsRefused(): void
    {
        $result = Punktacja::score('K', ['Zwierzę'], [
            'a' => ['Zwierzę' => 'K'],
        ], []);

        self::assertSame(0, $result['cells']['a']['Zwierzę']['points']);
        self::assertSame(Punktacja::STATUS_TOO_SHORT, $result['cells']['a']['Zwierzę']['status']);
    }

    /** Ale dwuliterowe slowo juz przechodzi - "Uz" to rzeka, "Oo" to ptak. */
    public function testTwoLettersAreEnough(): void
    {
        $result = Punktacja::score('K', ['Rzecz'], [
            'a' => ['Rzecz' => 'Ka'],
        ], []);

        self::assertSame(Punktacja::UNIQUE, $result['cells']['a']['Rzecz']['points']);
    }

    /** Panstwo spoza wykazu nie punktuje - to zbior zamkniety. */
    public function testAMadeUpCountryIsRefused(): void
    {
        $result = Punktacja::score('K', ['Państwo'], [
            'a' => ['Państwo' => 'Kanada'],
            'b' => ['Państwo' => 'Kanadia'],
        ], []);

        self::assertSame(Punktacja::UNIQUE, $result['cells']['a']['Państwo']['points']);
        self::assertSame(0, $result['cells']['b']['Państwo']['points']);
        self::assertSame(Punktacja::STATUS_UNKNOWN, $result['cells']['b']['Państwo']['status']);
    }

    /**
     * Wykaz obejmuje panstwa i miasta; przy zwierzeciu czy rzeczy serwer nie
     * ma sie na czym oprzec i nie udaje, ze ma.
     */
    public function testOpenColumnsAreNotVerified(): void
    {
        $result = Punktacja::score('K', ['Zwierzę'], [
            'a' => ['Zwierzę' => 'Kotoszczur'],
        ], []);

        self::assertSame(Punktacja::UNIQUE, $result['cells']['a']['Zwierzę']['points']);
    }

    /** Ostatnie slowo ma czlowiek: uznana odpowiedz punktuje mimo wykazu. */
    public function testHostCanAcceptAnAnswerFromOutsideTheList(): void
    {
        $result = Punktacja::score('K', ['Miasto'], [
            'a' => ['Miasto' => 'Kozia Wólka'],
        ], ['a' => ['Miasto' => true]]);

        self::assertSame(Punktacja::UNIQUE, $result['cells']['a']['Miasto']['points']);
    }

    /**
     * Odrzucona odpowiedz nie moze zrobic z cudzej poprawnej "powtorzonej" -
     * inaczej wpisanie bzdury zabieraloby przeciwnikom po piec punktow.
     */
    public function testARefusedAnswerDoesNotHalveSomebodyElsesPoints(): void
    {
        $result = Punktacja::score('K', ['Państwo'], [
            'a' => ['Państwo' => 'Kanada'],
            'b' => ['Państwo' => 'K'],
        ], []);

        self::assertSame(Punktacja::UNIQUE, $result['cells']['a']['Państwo']['points']);
        self::assertSame(0, $result['cells']['b']['Państwo']['points']);
    }

    /** Druzyna, ktora nie wpisala nic, ma byc w tabeli z zerem, a nie zniknac. */
    public function testTeamWithoutAnswersStaysInTheTable(): void
    {
        $result = Punktacja::score('K', self::COLUMNS, ['a' => []], []);

        self::assertSame(0, $result['totals']['a']);
        self::assertCount(2, $result['cells']['a']);
    }
}
