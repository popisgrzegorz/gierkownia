<?php

declare(strict_types=1);

namespace App\Tests\Content\Deezer;

use App\Content\Deezer\DeezerClient;
use App\Content\Deezer\DeezerImporter;
use App\Content\MediaLibrary;
use App\Content\TrackListImporter;
use App\Repository\TrackRepository;
use App\Tests\DoctrineTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Import z Deezera: prawdziwa baza i prawdziwe pliki na dysku (katalog
 * tymczasowy), tylko siec jest atrapa. To dysk i baza decyduja, czy utwor
 * zagra przy stole, wiec ich nie podmieniamy.
 */
final class DeezerImporterTest extends DoctrineTestCase
{
    private string $mediaDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mediaDir = sys_get_temp_dir().'/gierki-media-'.uniqid();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->mediaDir);

        parent::tearDown();
    }

    /** @return iterable<string, array{string, array{string, string}|null}> */
    public static function lineCases(): iterable
    {
        yield 'myslnik' => ['Maanam - Boskie Buenos', ['Maanam', 'Boskie Buenos']];
        yield 'polpauza' => ['Maanam – Boskie Buenos', ['Maanam', 'Boskie Buenos']];
        yield 'tabulator' => ["Maanam\tBoskie Buenos", ['Maanam', 'Boskie Buenos']];
        // Dzielimy na pierwszym separatorze: w tytule moze byc kolejny myslnik.
        yield 'myslnik w tytule' => ['Lady Pank - Mniej niz zero - wersja 2', ['Lady Pank', 'Mniej niz zero - wersja 2']];
        yield 'myslnik w nazwie bez spacji' => ['Jan-Maria - Piosenka', ['Jan-Maria', 'Piosenka']];
        yield 'komentarz' => ['# nic', null];
        yield 'pusta' => ['   ', null];
        yield 'bez separatora' => ['Samo wykonawca', null];
        yield 'pusty tytul' => ['Maanam - ', null];
    }

    /** @param array{string, string}|null $expected */
    #[DataProvider('lineCases')]
    public function testLineParsing(string $line, ?array $expected): void
    {
        self::assertSame($expected, DeezerImporter::parseLine($line));
    }

    public function testTrackIsDownloadedStoredLocallyAndAddedToTheCategory(): void
    {
        $importer = $this->importer(['Maanam - Boskie Buenos' => [10, 'Maanam', 'Boskie Buenos', 'MP3DANE']]);
        $category = $importer->categoryFor('Test Deezer '.uniqid());

        $counts = $importer->import(['Maanam - Boskie Buenos'], $category, pauseMs: 0);

        self::assertSame(1, $counts[DeezerImporter::ADDED]);

        $track = $category->getTracks()->first();
        self::assertNotFalse($track);
        self::assertSame('Boskie Buenos', $track->getTitle());
        self::assertSame('Maanam', $track->getArtist());
        // Plik lokalny, nie link: adres podgladu wygasa, a gra ma dzialac bez internetu.
        self::assertSame('deezer/10.mp3', $track->getPath());
        self::assertNull($track->getSourceUrl());
        self::assertSame('MP3DANE', file_get_contents($this->mediaDir.'/deezer/10.mp3'));
        self::assertFalse(file_exists($this->mediaDir.'/deezer/10.mp3.part'), 'Plik tymczasowy nie moze zostac po zapisie.');
    }

    public function testRunningTheSameImportTwiceDoesNotDuplicate(): void
    {
        $importer = $this->importer(['Maanam - Boskie Buenos' => [10, 'Maanam', 'Boskie Buenos', 'MP3DANE']]);
        $category = $importer->categoryFor('Test Deezer '.uniqid());

        $importer->import(['Maanam - Boskie Buenos'], $category, pauseMs: 0);
        $counts = $importer->import(['Maanam - Boskie Buenos'], $category, pauseMs: 0);

        self::assertSame(0, $counts[DeezerImporter::ADDED]);
        self::assertSame(1, $counts[DeezerImporter::EXISTS]);
        self::assertCount(1, $category->getTracks());
    }

    public function testDryRunSearchesButWritesNothing(): void
    {
        $importer = $this->importer(['Maanam - Boskie Buenos' => [10, 'Maanam', 'Boskie Buenos', 'MP3DANE']]);
        $category = $importer->categoryFor('Test Deezer '.uniqid());

        $counts = $importer->import(['Maanam - Boskie Buenos'], $category, dryRun: true, pauseMs: 0);

        self::assertSame(1, $counts[DeezerImporter::ADDED]);
        self::assertCount(0, $category->getTracks());
        self::assertFalse(is_dir($this->mediaDir.'/deezer'), 'Podglad nie moze nic pobierac.');
    }

    public function testMissingTrackAndMissingPreviewAreReportedSeparately(): void
    {
        $importer = $this->importer([
            'Maanam - Boskie Buenos' => [10, 'Maanam', 'Boskie Buenos', ''],
        ]);
        $category = $importer->categoryFor('Test Deezer '.uniqid());

        $counts = $importer->import([
            'Maanam - Boskie Buenos',
            'Nikt - Nieistniejaca piosenka',
            'to nie jest poprawna linia',
        ], $category, pauseMs: 0);

        self::assertSame(1, $counts[DeezerImporter::NO_PREVIEW]);
        self::assertSame(1, $counts[DeezerImporter::NOT_FOUND]);
        self::assertSame(1, $counts[DeezerImporter::INVALID]);
        self::assertCount(0, $category->getTracks());
    }

    public function testLimitStopsAfterThatManyNewTracks(): void
    {
        $importer = $this->importer([
            'A - Jeden' => [1, 'A', 'Jeden', 'x'],
            'B - Dwa' => [2, 'B', 'Dwa', 'x'],
            'C - Trzy' => [3, 'C', 'Trzy', 'x'],
        ]);
        $category = $importer->categoryFor('Test Deezer '.uniqid());

        $counts = $importer->import(['A - Jeden', 'B - Dwa', 'C - Trzy'], $category, limit: 2, pauseMs: 0);

        self::assertSame(2, $counts[DeezerImporter::ADDED]);
        self::assertCount(2, $category->getTracks());
    }

    public function testOneBrokenDownloadDoesNotStopTheRest(): void
    {
        // Pusty plik to blad pobierania; reszta listy ma dojsc do konca.
        $importer = $this->importer([
            'A - Jeden' => [1, 'A', 'Jeden', 'x'],
            'B - Dwa' => [2, 'B', 'Dwa', 'x'],
        ], emptyDownloadFor: 1);
        $category = $importer->categoryFor('Test Deezer '.uniqid());

        $counts = $importer->import(['A - Jeden', 'B - Dwa'], $category, pauseMs: 0);

        self::assertSame(1, $counts[DeezerImporter::ERROR]);
        self::assertSame(1, $counts[DeezerImporter::ADDED]);
    }

    /**
     * Atrapa Deezera: mapa "linia" -> [id, wykonawca, tytul, dane pliku]. Pusty
     * ciag w danych oznacza utwor bez podgladu; $emptyDownloadFor to id, ktorego
     * pobranie zwroci pusty plik.
     *
     * @param array<string, array{int, string, string, string}> $catalog
     */
    private function importer(array $catalog, ?int $emptyDownloadFor = null): DeezerImporter
    {
        $client = new DeezerClient(new MockHttpClient(function (string $method, string $url) use ($catalog, $emptyDownloadFor): MockResponse {
            if (str_starts_with($url, 'https://cdn.test/')) {
                $id = (int) basename($url, '.mp3');

                return new MockResponse($id === $emptyDownloadFor ? '' : 'MP3DANE');
            }

            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $q = isset($query['q']) && \is_string($query['q']) ? $query['q'] : '';
            $rows = [];

            foreach ($catalog as [$id, $artist, $title, $data]) {
                // Zapytanie scisle i luzne zawieraja i wykonawce, i tytul.
                if (str_contains($q, $artist) && str_contains($q, $title)) {
                    $rows[] = [
                        'id' => $id,
                        'title' => $title,
                        'artist' => ['name' => $artist],
                        'preview' => '' === $data ? '' : sprintf('https://cdn.test/%d.mp3', $id),
                    ];
                }
            }

            return new MockResponse((string) json_encode(['data' => $rows]), ['response_headers' => ['content-type: application/json']]);
        }));

        return new DeezerImporter(
            $client,
            new MediaLibrary($this->mediaDir),
            self::getContainer()->get(TrackListImporter::class),
            self::getContainer()->get(TrackRepository::class),
            $this->entityManager,
        );
    }
}
