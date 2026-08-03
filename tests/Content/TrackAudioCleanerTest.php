<?php

declare(strict_types=1);

namespace App\Tests\Content;

use App\Content\MediaLibrary;
use App\Content\TrackAudioCleaner;
use App\Entity\Category;
use App\Entity\Track;
use App\Repository\TrackRepository;
use App\Tests\DoctrineTestCase;

/**
 * Kasowanie pliku nagrania z dysku przy usuwaniu utworu - wspolne dla panelu
 * i dla kasowania calej kategorii. Plik dzielony przez kilka wierszy Track
 * (ten sam sygnal, kilka kategorii) musi przezyc usuniecie ktoregokolwiek z nich.
 */
final class TrackAudioCleanerTest extends DoctrineTestCase
{
    private string $dir;
    private TrackAudioCleaner $cleaner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/gierki-media-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0o775, true);

        /** @var TrackRepository $tracks */
        $tracks = self::getContainer()->get(TrackRepository::class);
        $this->cleaner = new TrackAudioCleaner(new MediaLibrary($this->dir), $tracks);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }

        parent::tearDown();
    }

    public function testDeletesTheFileWhenNoOtherTrackUsesIt(): void
    {
        $category = new Category('na-lato', 'Na lato', Category::KIND_MELODIA);
        $this->entityManager->persist($category);

        $absolute = $this->dir.'/sto-lat.mp3';
        file_put_contents($absolute, 'dzwiek');

        $track = new Track($category, 'sto-lat.mp3', 'Sto lat', 'Nieznany');
        $this->entityManager->persist($track);
        $this->entityManager->flush();

        $this->cleaner->deleteIfUnused($track);

        self::assertFileDoesNotExist($absolute);
    }

    /** Ten sam plik w dwoch kategoriach - usuniecie jednego wiersza nie moze zabrac go drugiemu. */
    public function testKeepsTheFileWhenAnotherTrackStillUsesIt(): void
    {
        $first = new Category('na-lato', 'Na lato', Category::KIND_MELODIA);
        $second = new Category('disco', 'Disco', Category::KIND_MELODIA);
        $this->entityManager->persist($first);
        $this->entityManager->persist($second);

        $absolute = $this->dir.'/sto-lat.mp3';
        file_put_contents($absolute, 'dzwiek');

        $trackA = new Track($first, 'sto-lat.mp3', 'Sto lat', 'Nieznany');
        $trackB = new Track($second, 'sto-lat.mp3', 'Sto lat', 'Nieznany');
        $this->entityManager->persist($trackA);
        $this->entityManager->persist($trackB);
        $this->entityManager->flush();

        $this->cleaner->deleteIfUnused($trackA);

        self::assertFileExists($absolute, 'Drugi wiersz nadal wskazuje ten sam plik.');
    }

    public function testDoesNothingForATrackWithoutAFile(): void
    {
        $category = new Category('na-lato', 'Na lato', Category::KIND_MELODIA);
        $this->entityManager->persist($category);

        $track = Track::fromUrl($category, 'https://przyklad.pl/utwor.mp3', 'Zewnętrzny', 'Nieznany');
        $this->entityManager->persist($track);
        $this->entityManager->flush();

        $this->cleaner->deleteIfUnused($track);

        self::assertDirectoryExists($this->dir);
    }
}
