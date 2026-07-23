<?php

declare(strict_types=1);

namespace App\Tests\Content;

use App\Content\TrackListImporter;
use App\Entity\Category;
use App\Tests\DoctrineTestCase;

/**
 * Import listy nagran z pliku tekstowego. Wejsciem jest plik pisany maszynowo
 * albo poprawiany recznie - i to drugie decyduje o tym, ile tu asercji.
 */
final class TrackListImporterTest extends DoctrineTestCase
{
    private TrackListImporter $importer;
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importer = self::getContainer()->get(TrackListImporter::class);
        $this->file = sys_get_temp_dir().'/lista-'.uniqid().'.txt';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }

        parent::tearDown();
    }

    public function testTracksGetTitleArtistAndExternalLink(): void
    {
        $this->write([
            "Pierwsza\tZespół A\thttps://example.test/a.mp3",
            "Druga\tZespół B\thttps://example.test/b.mp3",
        ]);

        $category = $this->importer->categoryFor('Ballady testowe');
        $result = $this->importer->import($this->file, $category);

        self::assertSame(2, $result['added']);
        self::assertCount(2, $category->getTracks());

        $first = $category->getTracks()->first();
        self::assertNotFalse($first);
        self::assertSame('Pierwsza', $first->getTitle());
        self::assertSame('Zespół A', $first->getArtist());
        self::assertSame('https://example.test/a.mp3', $first->getSourceUrl());

        // Nagranie z sieci nie ma pliku na dysku - i nie wolno, zeby udawalo,
        // ze ma: kontroler mediow wybiera droge wlasnie po tym polu.
        self::assertNull($first->getPath());
        self::assertTrue($first->isExternal());
    }

    /** Powtorzony import to norma - lista rosnie, a nie dubluje sie. */
    public function testRepeatedImportAddsOnlyWhatIsNew(): void
    {
        $this->write(["Pierwsza\tZespół A\thttps://example.test/a.mp3"]);
        $category = $this->importer->categoryFor('Ballady testowe');
        $this->importer->import($this->file, $category);

        $this->write([
            "Pierwsza\tZespół A\thttps://example.test/a.mp3",
            "Nowa\tZespół C\thttps://example.test/c.mp3",
        ]);
        $result = $this->importer->import($this->file, $category);

        self::assertSame(1, $result['added']);
        self::assertSame(1, $result['skipped']);
        self::assertCount(2, $category->getTracks());
    }

    /**
     * Kilka nagran z sieci w jednej kategorii - unikalny indeks stoi na (kategoria,
     * sciezka), wiec pusta sciezka zamiast NULL-a wywrocilaby drugi wiersz.
     */
    public function testSeveralExternalTracksFitInOneCategory(): void
    {
        $this->write([
            "Jeden\tA\thttps://example.test/1.mp3",
            "Dwa\tB\thttps://example.test/2.mp3",
            "Trzy\tC\thttps://example.test/3.mp3",
        ]);

        $category = $this->importer->categoryFor('Ballady testowe');
        $this->importer->import($this->file, $category);

        $this->entityManager->flush();

        self::assertCount(3, $category->getTracks());
    }

    /** Wiersz bez adresu albo z adresem względnym nie jest utworem. */
    public function testBrokenRowsAreCountedNotImported(): void
    {
        $this->write([
            '# komentarz',
            '',
            'Sam tytuł bez reszty',
            "Bez adresu\tZespół\t",
            "Zły adres\tZespół\t/baza/lokalny.mp3",
            "Dobra\tZespół\thttps://example.test/ok.mp3",
        ]);

        $category = $this->importer->categoryFor('Ballady testowe');
        $result = $this->importer->import($this->file, $category);

        self::assertSame(1, $result['added']);
        self::assertSame(4, $result['invalid']);
    }

    /** Brak wykonawcy w pliku nie moze dac pustego podpisu na telewizorze. */
    public function testMissingArtistFallsBackToUnknown(): void
    {
        $this->write(["Tytuł\t\thttps://example.test/x.mp3"]);

        $category = $this->importer->categoryFor('Ballady testowe');
        $this->importer->import($this->file, $category);

        $track = $category->getTracks()->first();
        self::assertNotFalse($track);
        self::assertSame('Nieznany', $track->getArtist());
    }

    public function testPreviewChangesNothingInTheDatabase(): void
    {
        $this->write(["Pierwsza\tZespół A\thttps://example.test/a.mp3"]);

        $category = $this->importer->categoryFor('Ballady testowe');
        $result = $this->importer->import($this->file, $category, dryRun: true);

        self::assertSame(1, $result['added']);
        self::assertCount(0, $category->getTracks());
    }

    /** Kategoria ze slownikiem hasel nie moze przyjac nagran. */
    public function testDictionaryCategoryIsRefused(): void
    {
        $category = new Category('slownik-test', 'Słownik testowy', Category::KIND_HASLA, ['kolo']);
        $this->entityManager->persist($category);
        $this->entityManager->flush();

        $this->write(["Tytuł\tZespół\thttps://example.test/x.mp3"]);

        $this->expectException(\RuntimeException::class);

        $this->importer->import($this->file, $category);
    }

    /** @param list<string> $lines */
    private function write(array $lines): void
    {
        file_put_contents($this->file, implode("\n", $lines)."\n");
    }
}
