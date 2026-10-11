<?php

declare(strict_types=1);

namespace App\Content\Deezer;

use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Wyszukiwanie utworow w publicznym API Deezera i pobieranie 30-sekundowych
 * fragmentow (pole "preview"). Zadnego klucza ani konta - to ten sam kanal,
 * ktorym Deezer udostepnia podglady na stronach zewnetrznych.
 *
 * Adresy podgladow sa podpisane i po jakims czasie przestaja dzialac, wiec
 * nigdy ich nie zapisujemy w bazie: importer pobiera plik od razu i trzyma go
 * u siebie (dzieki temu gra dziala tez bez internetu).
 */
final readonly class DeezerClient
{
    private const SEARCH_URL = 'https://api.deezer.com/search';

    public function __construct(
        private HttpClientInterface $http,
    ) {
    }

    /**
     * Najlepsze trafienie dla wykonawcy i tytulu albo null, gdy Deezer takiego
     * utworu nie zna. Najpierw zapytanie scisle (pola artist/track), potem
     * luzne - scisle gubi utwory, ktorych tytul rozni sie drobiazgiem.
     *
     * @return array{id: int, artist: string, title: string, preview: string}|null
     *
     * @throws \RuntimeException przy bledzie sieci albo odpowiedzi, ktorej nie rozumiemy
     */
    public function find(string $artist, string $title): ?array
    {
        $queries = [
            sprintf('artist:"%s" track:"%s"', $this->plain($artist), $this->plain($title)),
            sprintf('%s %s', $this->plain($artist), $this->plain($title)),
        ];

        $fallback = null;

        foreach ($queries as $query) {
            foreach ($this->search($query) as $hit) {
                if (!self::matches($artist, $title, $hit['artist'], $hit['title'])) {
                    continue;
                }

                if (!self::isVariant($hit['title'], $title)) {
                    return $hit;
                }

                // Wersja live/remix tez jest tym utworem, ale tylko ostatecznoscia:
                // jesli gdzies dalej jest studyjna, ma pierwszenstwo.
                $fallback ??= $hit;
            }
        }

        return $fallback;
    }

    /**
     * Czy trafienie z Deezera to ten utwor, o ktory prosimy. Porownanie jest
     * celowo wyrozumiale na ozdobniki ("(2011 Remaster)", "feat."), ale nie na
     * zupelnie inny tytul - zla piosenka w quizie jest gorsza niz brak piosenki.
     */
    public static function matches(string $wantedArtist, string $wantedTitle, string $foundArtist, string $foundTitle): bool
    {
        $titleA = self::normalize($wantedTitle);
        $titleB = self::normalize($foundTitle);

        if ('' === $titleA || '' === $titleB) {
            return false;
        }

        $artistA = self::normalize($wantedArtist);
        $artistB = self::normalize($foundArtist);

        $sameTitle = $titleA === $titleB;
        $sameArtist = '' === $artistA
            || str_contains($artistB, $artistA)
            || str_contains($artistA, $artistB);

        return $sameTitle && $sameArtist;
    }

    /**
     * Czy tytul z Deezera to wariant (live, remix, karaoke...), o ktory nie
     * prosilismy. Jesli sami wpisalismy "Live" w tytule, to nie jest wariant.
     */
    public static function isVariant(string $foundTitle, string $wantedTitle): bool
    {
        $pattern = '/\b(live|remix|karaoke|instrumental|acoustic|akustycznie|cover|demo)\b/iu';

        return 1 === preg_match($pattern, $foundTitle) && 1 !== preg_match($pattern, $wantedTitle);
    }

    /** Pobiera fragment. Zwraca bajty pliku mp3. */
    public function download(string $previewUrl): string
    {
        try {
            $response = $this->http->request('GET', $previewUrl, ['timeout' => 20]);
            $body = $response->getContent();
        } catch (ExceptionInterface $e) {
            throw new \RuntimeException('Nie udalo sie pobrac fragmentu: '.$e->getMessage(), 0, $e);
        }

        if ('' === $body) {
            throw new \RuntimeException('Deezer zwrocil pusty plik.');
        }

        return $body;
    }

    /**
     * Mala litera, bez ogonkow, bez dopiskow w nawiasach i po myslniku
     * ("Boskie Buenos - 2011 Remaster" to ten sam utwor co "Boskie Buenos").
     */
    public static function normalize(string $text): string
    {
        $text = (string) preg_replace('/[\(\[][^\)\]]*[\)\]]/u', ' ', $text);
        $text = (string) preg_replace('/\s+[-–—]\s+.*$/u', '', $text);
        $text = (string) preg_replace('/\b(feat|ft)\.?\s.*$/iu', '', $text);

        return trim((new AsciiSlugger())->slug($text, ' ')->lower()->toString());
    }

    /**
     * @return list<array{id: int, artist: string, title: string, preview: string}>
     */
    private function search(string $query): array
    {
        try {
            $data = $this->http->request('GET', self::SEARCH_URL, [
                'query' => ['q' => $query, 'limit' => 10],
                'timeout' => 15,
            ])->toArray();
        } catch (ExceptionInterface $e) {
            throw new \RuntimeException('Blad zapytania do Deezera: '.$e->getMessage(), 0, $e);
        }

        if (isset($data['error'])) {
            throw new \RuntimeException('Deezer odmowil zapytania (limit zapytan?). Sprobuj za chwile.');
        }

        $rows = $data['data'] ?? [];
        $hits = [];

        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (!\is_array($row)
                || !\is_int($row['id'] ?? null)
                || !\is_string($row['title'] ?? null)
                || !\is_array($row['artist'] ?? null)
                || !\is_string($row['artist']['name'] ?? null)
                || !\is_string($row['preview'] ?? null)
            ) {
                continue;
            }

            $hits[] = [
                'id' => $row['id'],
                'artist' => $row['artist']['name'],
                'title' => $row['title'],
                // Pusty adres to utwor bez podgladu (umowy licencyjne) - nie ma czego grac.
                'preview' => $row['preview'],
            ];
        }

        return $hits;
    }

    /** Cudzyslow w nazwie rozwalilby zapytanie z polami, wiec go nie przepuszczamy. */
    private function plain(string $text): string
    {
        return trim(str_replace('"', ' ', $text));
    }
}
