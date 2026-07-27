<?php

declare(strict_types=1);

namespace App\Game\PanstwaMiasta;

/**
 * Liczenie punktow za wypelniona kartke.
 *
 * Zasady klasyczne: 10 punktow za odpowiedz, ktorej nikt inny nie wpisal,
 * 5 gdy ktos wpadl na to samo, 0 za puste pole i za zla litere.
 *
 * Logika siedzi osobno od modulu gry, bo to jedyne miejsce, w ktorym da sie
 * pomylic na niekorzysc konkretnej druzyny - a przy stole nikt nie bedzie
 * przegladal Redisa, zeby udowodnic swoje.
 *
 * Automat odrzuca tylko to, co da sie rozstrzygnac bez slownika jezyka:
 * zla pierwsza litera, jedna litera zamiast slowa oraz - w rubrykach "Panstwo"
 * i "Miasto" - odpowiedz spoza wykazu (patrz `Wykaz`). Dla rosliny, zwierzecia
 * czy rzeczy serwer nie ocenia niczego: nie zna slownika jezyka polskiego
 * i nie bedzie udawal, ze zna.
 *
 * Kazde odrzucenie jest **miekkie**: host uznaje komorke jednym klikniecem
 * (`overrides`), wiec "Kanadaa" z literowka wchodzi do punktacji, a wymyslone
 * "Krzyzowiec morski" zostaje odrzucone.
 */
final class Punktacja
{
    /** Odpowiedz, ktorej nikt inny nie wpisal. */
    public const UNIQUE = 10;

    /** To samo slowo u kilku druzyn. */
    public const SHARED = 5;

    /**
     * Ponizej tylu liter to nie jest odpowiedz.
     *
     * Jedna litera zdarza sie, gdy ktos zaczyna pisac i nie zdazy przed STOP-em
     * albo wstukuje cokolwiek dla punktu. Nie ma polskiego panstwa, miasta ani
     * zwierzecia o jednoliterowej nazwie, wiec nie ma czego uznawac.
     */
    public const MIN_LETTERS = 2;

    public const STATUS_UNIQUE = 'unique';
    public const STATUS_SHARED = 'shared';
    public const STATUS_EMPTY = 'empty';
    public const STATUS_WRONG_LETTER = 'wrong_letter';
    public const STATUS_REJECTED = 'rejected';

    /** Jedna litera zamiast slowa. */
    public const STATUS_TOO_SHORT = 'too_short';

    /** Slowo spoza wykazu panstw albo miast - do decyzji hosta. */
    public const STATUS_UNKNOWN = 'unknown';

    /**
     * @param list<string>                            $columns
     * @param array<string, array<string, string>>    $answers   teamId => kolumna => tresc
     * @param array<string, array<string, bool>>      $overrides teamId => kolumna => uznana?
     *
     * @return array{
     *     cells: array<string, array<string, array{text: string, points: int, status: string, forced: bool}>>,
     *     totals: array<string, int>
     * }
     */
    public static function score(string $letter, array $columns, array $answers, array $overrides): array
    {
        $cells = [];
        $totals = [];

        foreach (array_keys($answers) as $teamId) {
            $cells[$teamId] = [];
            $totals[$teamId] = 0;
        }

        foreach ($columns as $column) {
            // Klucz "to samo slowo" dla kazdej punktujacej odpowiedzi. Literowki
            // tego samego panstwa dostaja ten sam klucz, wiec licza sie jak jedna
            // odpowiedz - a to wlasnie rozstrzyga o 10 (unikat) kontra 5 (powtorka).
            $keys = self::canonicalKeys($letter, $column, $answers, $overrides);

            $counts = [];

            foreach ($keys as $key) {
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }

            foreach ($answers as $teamId => $row) {
                $text = trim($row[$column] ?? '');
                $override = $overrides[$teamId][$column] ?? null;
                $cells[$teamId][$column] = self::cell($letter, $column, $text, $override, $keys[$teamId] ?? null, $counts);
                $totals[$teamId] += $cells[$teamId][$column]['points'];
            }
        }

        return ['cells' => $cells, 'totals' => $totals];
    }

    /**
     * Klucz porownania dla kazdej punktujacej odpowiedzi w kolumnie: teamId => klucz.
     *
     * Odpowiedzi, ktore nie punktuja (puste, zla litera, odrzucone), klucza nie
     * dostaja - nie moga byc ani unikatem, ani powtorka.
     *
     * Sedno mechanizmu literowek: dwa zapisy tego samego slowa dostaja ten sam
     * klucz. W rubrykach z wykazem (panstwo, miasto) prostujemy literowke do
     * wpisu z wykazu - "Kanadaa" i "Kanda" obie zjezdzaja na "kanada". Tam, gdzie
     * wykazu nie ma, laczymy zapisy miedzy druzynami: pierwsza zapisana forma
     * staje sie wzorcem, a bliskie jej literowki podpinaja sie pod nia.
     *
     * @param array<string, array<string, string>> $answers   teamId => kolumna => tresc
     * @param array<string, array<string, bool>>   $overrides teamId => kolumna => uznana?
     *
     * @return array<string, string>
     */
    private static function canonicalKeys(string $letter, string $column, array $answers, array $overrides): array
    {
        $covered = Wykaz::covers($column);
        $keys = [];
        $reps = [];

        foreach ($answers as $teamId => $row) {
            $text = $row[$column] ?? '';

            if (null !== self::problem($letter, $column, $text, $overrides[$teamId][$column] ?? null)) {
                continue;
            }

            $norm = self::normalize($text);

            if ($covered) {
                // Wpis z wykazu prostuje literowke; odpowiedz spoza wykazu (uznana
                // recznie przez hosta) zostaje soba.
                $keys[$teamId] = Wykaz::resolve($column, $text) ?? $norm;

                continue;
            }

            // Bez wykazu nie ma do czego prostowac, wiec pierwszy zapis staje sie
            // wzorcem, a jego literowki lgna do niego.
            $match = $norm;

            foreach ($reps as $rep) {
                if (self::sameWord($rep, $norm)) {
                    $match = $rep;

                    break;
                }
            }

            if ($match === $norm) {
                $reps[] = $norm;
            }

            $keys[$teamId] = $match;
        }

        return $keys;
    }

    /**
     * @param array<string, int> $counts
     *
     * @return array{text: string, points: int, status: string, forced: bool}
     */
    private static function cell(string $letter, string $column, string $text, ?bool $override, ?string $key, array $counts): array
    {
        $problem = self::problem($letter, $column, $text, $override);

        if (null !== $problem) {
            return [
                'text' => $text,
                'points' => 0,
                'status' => $problem,
                'forced' => self::STATUS_REJECTED === $problem,
            ];
        }

        $shared = null !== $key && ($counts[$key] ?? 0) > 1;

        return [
            'text' => $text,
            'points' => $shared ? self::SHARED : self::UNIQUE,
            'status' => $shared ? self::STATUS_SHARED : self::STATUS_UNIQUE,
            'forced' => null !== $override,
        ];
    }

    /**
     * Powod, dla ktorego odpowiedz nie punktuje - albo null, gdy wszystko z nia
     * w porzadku. Jedna funkcja dla zliczania powtorzen i dla oceny komorki:
     * gdyby rozjechaly sie choc o jeden warunek, odrzucone slowo robilo by
     * z cudzej poprawnej odpowiedzi "powtorzona" i zabieralo 5 punktow.
     */
    private static function problem(string $letter, string $column, string $text, ?bool $override): ?string
    {
        $text = trim($text);

        if ('' === $text) {
            // Pustego pola nie da sie uznac - nie ma czego uznawac.
            return self::STATUS_EMPTY;
        }

        if (false === $override) {
            return self::STATUS_REJECTED;
        }

        // Decyzja hosta konczy sprawe: to on siedzi przy telewizorze i widzi
        // kartke, a nie odwrotnie.
        if (true === $override) {
            return null;
        }

        if (!self::startsWith($letter, $text)) {
            return self::STATUS_WRONG_LETTER;
        }

        if (self::MIN_LETTERS > mb_strlen(preg_replace('/[^\p{L}]/u', '', $text) ?? '')) {
            return self::STATUS_TOO_SHORT;
        }

        if (Wykaz::covers($column) && !Wykaz::knows($column, $text)) {
            return self::STATUS_UNKNOWN;
        }

        return null;
    }

    private static function startsWith(string $letter, string $text): bool
    {
        return self::normalize($letter) === mb_substr(self::normalize($text), 0, 1);
    }

    /**
     * Postac do porownywania odpowiedzi.
     *
     * Bez ogonkow i bez wielkosci liter, bo "Łódź", "Lodz" i "łódź" to przy
     * stole jedno i to samo miasto - a gdyby liczyly sie jako rozne, kazda
     * druzyna dostawalaby 10 punktow za to samo slowo.
     */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = strtr($text, [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
            'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        ]);

        return (string) preg_replace('/\s+/u', ' ', $text);
    }

    /**
     * Czy dwa (znormalizowane) zapisy to to samo slowo z dokladnoscia do literowki.
     *
     * Prog rosnie z dlugoscia: przy krotkim slowie jedna zmiana to zwykle inne
     * slowo ("kot"/"kat", "chiny"/"chile"), przy dluzszym - niemal na pewno
     * literowka ("luksemburg"/"luxemburg"). Dlatego slowa do czterech liter
     * musza zgadzac sie znak w znak.
     *
     * Porownujemy postac znormalizowana, wiec bez ogonkow i wielkosci liter -
     * a ta jest juz czystym ASCII, na ktorym bajtowy `levenshtein` liczy znaki.
     */
    public static function sameWord(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        $la = \strlen($a);
        $lb = \strlen($b);

        // Roznica dlugosci wieksza niz prog to od razu inne slowo - i przy okazji
        // odsiewamy pary, ktorych nie warto liczyc znak po znaku.
        if (abs($la - $lb) > 2) {
            return false;
        }

        return levenshtein($a, $b) <= self::typoTolerance(max($la, $lb));
    }

    /** Ile przekreconych znakow wciaz uznajemy za to samo slowo. */
    private static function typoTolerance(int $length): int
    {
        return match (true) {
            $length <= 4 => 0,
            $length <= 7 => 1,
            default => 2,
        };
    }
}
