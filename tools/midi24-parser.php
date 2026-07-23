<?php

declare(strict_types=1);

/**
 * Parser listy utworow z midi24.pl.
 *
 * Czyta publiczna strone kategorii i wyciaga z kazdego bloku `.box_utworu`:
 * tytul i wykonawce (`.u_tytul` - pierwsza linia to tytul, druga wykonawca)
 * oraz adres nagrania demonstracyjnego (`.u_demo` -> <audio>, gdzie `src`
 * siedzi na zagniezdzonym <source>, a nie na samym <audio>).
 *
 * Wynikiem jest plik tekstowy: jedna linia na utwor, pola rozdzielone
 * tabulatorem (tytul, wykonawca, adres). Skrypt **nie pobiera** plikow audio -
 * zapisuje sam spis. Katalog `/baza/` jest u nich wykluczony w robots.txt,
 * wiec adresy zostaja adresami.
 *
 * Skrypt stoi poza `src/`, bo nie jest czescia gry: nie potrzebuje kontenera,
 * bazy ani Symfony i nie ma prawa wjechac do aplikacji razem z nia.
 *
 * Uzycie:
 *   php tools/midi24-parser.php
 *   php tools/midi24-parser.php "https://www.midi24.pl/kategoria/26/0/Ballady-rockowe.html"
 *   php tools/midi24-parser.php --strony=5 --out=ballady.txt --przerwa=2000
 *
 * Opcje:
 *   --out=PLIK       gdzie zapisac wynik (domyslnie z nazwy kategorii)
 *   --strony=N       ile kolejnych podstron przejsc, liczac od podanej (domyslnie 1)
 *   --przerwa=MS     odstep miedzy zadaniami w milisekundach (domyslnie 1500)
 */

const DEFAULT_URL = 'https://www.midi24.pl/kategoria/26/0/Ballady-rockowe.html';
const USER_AGENT = 'Mozilla/5.0 (kompatybilny; parser listy utworow do prywatnego uzytku)';

/** @return array{url: string, out: ?string, pages: int, delay: int} */
function parseArguments(): array
{
    $url = DEFAULT_URL;
    $out = null;
    $pages = 1;
    $delay = 1500;

    foreach (\array_slice($_SERVER['argv'] ?? [], 1) as $argument) {
        if (str_starts_with($argument, '--out=')) {
            $out = substr($argument, 6);
        } elseif (str_starts_with($argument, '--strony=')) {
            $pages = max(1, (int) substr($argument, 9));
        } elseif (str_starts_with($argument, '--przerwa=')) {
            $delay = max(0, (int) substr($argument, 10));
        } elseif (!str_starts_with($argument, '--')) {
            $url = $argument;
        }
    }

    return ['url' => $url, 'out' => $out, 'pages' => $pages, 'delay' => $delay];
}

function fetch(string $url): string
{
    $context = stream_context_create(['http' => [
        'method' => 'GET',
        'timeout' => 30,
        'follow_location' => 1,
        'header' => "User-Agent: ".USER_AGENT."\r\nAccept: text/html\r\nAccept-Language: pl,en\r\n",
    ]]);

    $html = @file_get_contents($url, false, $context);

    if (false === $html || '' === $html) {
        throw new RuntimeException(sprintf('Nie udalo sie pobrac "%s".', $url));
    }

    return $html;
}

function document(string $html): DOMXPath
{
    // libxml zasypuje wyjscie ostrzezeniami o kazdym niedomknietym <BR>,
    // a strona jest recznie sklejanym HTML-em - te bledy nic nam nie mowia.
    $previous = libxml_use_internal_errors(true);

    $dom = new DOMDocument();
    // Bez tej deklaracji loadHTML czyta bajty jako ISO-8859-1 i polskie znaki
    // rozpadaja sie na krzaki, mimo ze strona jest w UTF-8.
    $dom->loadHTML('<?xml encoding="UTF-8">'.$html, \LIBXML_NOWARNING | \LIBXML_NOERROR);

    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return new DOMXPath($dom);
}

/**
 * XPath po klasie CSS. Sama funkcja contains() dopasowalaby "u_demo" takze
 * w "u_demo_extra", a na tej stronie klasy bywaja z ogonem ("u_demo ").
 */
function byClass(string $class): string
{
    return sprintf("contains(concat(' ', normalize-space(@class), ' '), ' %s ')", $class);
}

/**
 * Tytul i wykonawca z bloku `.u_tytul`.
 *
 * Blok ma postac: <a>Tytul</a><BR><a>Wykonawca</a>. Dzielimy po <BR>, a nie po
 * kolejnych <a>, bo wykonawca bywa zwyklym tekstem bez odnosnika.
 *
 * @return array{string, string}
 */
function titleAndArtist(DOMXPath $xpath, DOMNode $box): array
{
    $node = $xpath->query('.//*['.byClass('u_tytul').']', $box)?->item(0);

    if (!$node instanceof DOMElement) {
        return ['', ''];
    }

    $html = (string) $node->ownerDocument?->saveHTML($node);
    $lines = preg_split('#<br\s*/?>#i', $html) ?: [];

    $lines = array_values(array_filter(array_map(
        static fn (string $line): string => trim(html_entity_decode(
            strip_tags($line),
            \ENT_QUOTES | \ENT_HTML5,
            'UTF-8',
        )),
        $lines,
    ), static fn (string $line): bool => '' !== $line));

    return [$lines[0] ?? '', $lines[1] ?? ''];
}

/** Adres nagrania z bloku `.u_demo`; `src` jest na <source> wewnatrz <audio>. */
function demoUrl(DOMXPath $xpath, DOMNode $box, string $base): string
{
    $query = './/*['.byClass('u_demo').']//audio/@src | .//*['.byClass('u_demo').']//audio/source/@src';

    foreach ($xpath->query($query, $box) ?: [] as $attribute) {
        $value = trim($attribute->nodeValue ?? '');

        if ('' !== $value) {
            return absolutize($value, $base);
        }
    }

    return '';
}

function absolutize(string $url, string $base): string
{
    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }

    $parts = parse_url($base);
    $origin = sprintf('%s://%s', $parts['scheme'] ?? 'https', $parts['host'] ?? 'www.midi24.pl');

    return $origin.'/'.ltrim($url, '/');
}

/**
 * Adres kolejnej podstrony. W tym serwisie numer strony jest drugim segmentem
 * sciezki (/kategoria/26/0/...), liczonym od zera.
 */
function pageUrl(string $url, int $page): string
{
    return (string) preg_replace_callback(
        '#/kategoria/(\d+)/(\d+)/#',
        static fn (array $m): string => sprintf('/kategoria/%s/%d/', $m[1], $page),
        $url,
    );
}

/** @return list<array{string, string, string}> */
function parsePage(string $html, string $base): array
{
    $xpath = document($html);
    $rows = [];

    foreach ($xpath->query('//*['.byClass('box_utworu').']') ?: [] as $box) {
        [$title, $artist] = titleAndArtist($xpath, $box);
        $demo = demoUrl($xpath, $box, $base);

        if ('' === $title && '' === $demo) {
            continue;
        }

        $rows[] = [$title, $artist, $demo];
    }

    return $rows;
}

// --- przebieg ---------------------------------------------------------------

$options = parseArguments();
$rows = [];
$seen = [];

// Numer strony z adresu podanego przez uzytkownika: --strony liczy sie
// od miejsca, w ktorym kazano zaczac, a nie zawsze od pierwszej.
preg_match('#/kategoria/\d+/(\d+)/#', $options['url'], $match);
$first = (int) ($match[1] ?? 0);

for ($i = 0; $i < $options['pages']; ++$i) {
    $url = pageUrl($options['url'], $first + $i);
    fprintf(\STDERR, "pobieram: %s\n", $url);

    try {
        $page = parsePage(fetch($url), $url);
    } catch (RuntimeException $e) {
        fprintf(\STDERR, "  blad: %s\n", $e->getMessage());

        break;
    }

    if ([] === $page) {
        fprintf(\STDERR, "  brak utworow - koniec kategorii\n");

        break;
    }

    foreach ($page as $row) {
        // Ten sam utwor potrafi wrocic na kolejnej podstronie, gdy katalog
        // przestawi sie miedzy zadaniami.
        $key = $row[2].'|'.$row[0];

        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $rows[] = $row;
        }
    }

    fprintf(\STDERR, "  utworow: %d (razem %d)\n", \count($page), \count($rows));

    if ($i + 1 < $options['pages'] && $options['delay'] > 0) {
        usleep($options['delay'] * 1000);
    }
}

if ([] === $rows) {
    fwrite(\STDERR, "Nic nie znalazlem - sprawdz adres.\n");

    exit(1);
}

$name = $options['out'] ?? sprintf(
    'midi24-%s.txt',
    strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', basename(parse_url($options['url'], \PHP_URL_PATH) ?: 'kategoria', '.html'))),
);

$lines = array_map(
    static fn (array $row): string => implode("\t", $row),
    $rows,
);

file_put_contents($name, implode("\n", $lines)."\n");

$withoutDemo = \count(array_filter($rows, static fn (array $r): bool => '' === $r[2]));

printf("Zapisano %d utworow do %s\n", \count($rows), $name);

if ($withoutDemo > 0) {
    printf("Bez adresu nagrania: %d\n", $withoutDemo);
}
